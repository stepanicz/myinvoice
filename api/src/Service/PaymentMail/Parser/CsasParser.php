<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail\Parser;

use MyInvoice\Service\PaymentMail\EmailMessage;
use MyInvoice\Service\PaymentMail\ParsedTransaction;

/**
 * Parser notifikačních mailů České spořitelny (kód banky 0800).
 *
 * Šablona (Quadient Inspire Designer MIME, viz X-Mailer):
 *   - From: ceskasporitelna@csas.cz
 *   - Subject (base64 UTF-8): "Přišla platba"
 *   - multipart/mixed, jen HTML část (qprintable, UTF-8) + 2 inline PNG
 *   - hodnoty rozsekané přes <span> tagy → strip_tags + regex na štítky
 *
 * Klíčové fragmenty (po strip_tags + html_entity_decode + collapse whitespace):
 *   "na účet 7055602369/0800 právě dorazila platba ve výši 2 500,00 Kč."
 *   "Směr platby: příchozí"
 *   "Číslo účtu: 7055602369/0800"
 *   "Číslo účtu protistrany: 2403144606/2010"
 *   "Částka v měně účtu: 2 500,00 Kč"
 *   "Variabilní symbol: 2026169"
 *   "Konstantní symbol: 0308"
 *   "Zpráva pro příjemce: Faktura 2026169"
 */
final class CsasParser implements BankParserInterface
{
    public function name(): string
    {
        return 'csas';
    }

    public function supports(EmailMessage $msg): bool
    {
        // Primárně podle odesílatele; subject jako sekundární kontrola.
        if (str_ends_with($msg->fromAddress, '@csas.cz')) {
            return true;
        }
        if (stripos($msg->fromAddress, 'ceskasporitelna') !== false) {
            return true;
        }
        return false;
    }

    public function parse(EmailMessage $msg): ParsedTransaction
    {
        $text = $this->htmlToFlatText($msg->bodyHtml);
        if ($text === '') {
            throw new \RuntimeException('CSAS: prázdné HTML tělo mailu.');
        }

        // Recipient account (z věty "na účet X/0800 právě dorazila…" nebo z bloku "Číslo účtu: X/0800")
        $recipientAccount = '';
        $bankCode = '0800';
        if (preg_match('/Číslo účtu:\s*([\d\-]+)\s*\/\s*(\d{4})/u', $text, $m)
            || preg_match('/na účet\s+([\d\-]+)\s*\/\s*(\d{4})\s+právě dorazila/u', $text, $m)) {
            $recipientAccount = $this->normalizeAccount($m[1]);
            $bankCode = $m[2];
        }

        // Counterparty
        $counterpartyAccount = '';
        $counterpartyBank = null;
        if (preg_match('/Číslo účtu protistrany:\s*([\d\-]+)\s*\/\s*(\d{4})/u', $text, $m)) {
            $counterpartyAccount = $this->normalizeAccount($m[1]);
            $counterpartyBank = $m[2];
        }

        // Direction (default incoming — CSAS posílá tento template hlavně pro příchozí)
        $direction = 'incoming';
        if (preg_match('/Směr platby:\s*(\S+)/u', $text, $m)) {
            $direction = (mb_strtolower($m[1]) === 'odchozí') ? 'outgoing' : 'incoming';
        }

        // Amount — preferuj "Částka v měně účtu", fallback na úvodní větu
        $amount = null;
        $currency = 'CZK';
        if (preg_match('/Částka v měně účtu:\s*([\d\s\xC2\xA0,\.]+)\s*(Kč|EUR|USD|CZK)/u', $text, $m)) {
            $amount = $this->normalizeAmount($m[1]);
            $currency = $this->normalizeCurrency($m[2]);
        } elseif (preg_match('/dorazila platba ve výši\s*([\d\s\xC2\xA0,\.]+)\s*(Kč|EUR|USD|CZK)/u', $text, $m)) {
            $amount = $this->normalizeAmount($m[1]);
            $currency = $this->normalizeCurrency($m[2]);
        }
        if ($amount === null) {
            throw new \RuntimeException('CSAS: nepodařilo se rozpoznat částku v notifikaci.');
        }
        // Negate amount pokud odchozí — bank_transactions.amount je signed (kladná = příchozí, záporná = odchozí)
        if ($direction === 'outgoing' && $amount[0] !== '-') {
            $amount = '-' . $amount;
        }

        // VS — volitelný; bez VS se transakce uloží jako nepárovaná (ruční párování v UI).
        $variableSymbol = null;
        if (preg_match('/Variabilní symbol:\s*(\d+)/u', $text, $m)) {
            $variableSymbol = $m[1];
        }

        $constantSymbol = null;
        if (preg_match('/Konstantní symbol:\s*(\d+)/u', $text, $m)) {
            $constantSymbol = $m[1];
        }
        $specificSymbol = null;
        if (preg_match('/Specifický symbol:\s*(\d+)/u', $text, $m)) {
            $specificSymbol = $m[1];
        }

        // Zpráva pro příjemce — vezmi až do konce stringu, pak ořež případné následující štítky.
        // (V CSAS šabloně může být buď před, nebo za blokem symbolů — ošetřujeme oba případy.)
        $message = null;
        if (preg_match('/Zpráva pro příjemce:\s*(.*)$/u', $text, $m)) {
            $msgText = trim($m[1]);
            foreach (['Variabilní symbol', 'Konstantní symbol', 'Specifický symbol', 'Datum', 'Bezpečí', 'Vaše Česká'] as $label) {
                $pos = mb_strpos($msgText, $label . ':');
                if ($pos !== false) {
                    $msgText = trim(mb_substr($msgText, 0, $pos));
                }
            }
            $message = $msgText !== '' ? mb_substr($msgText, 0, 255) : null;
        }

        return new ParsedTransaction(
            bankCode:             $bankCode,
            recipientAccount:     $recipientAccount,
            counterpartyAccount:  $counterpartyAccount,
            counterpartyBankCode: $counterpartyBank,
            variableSymbol:       $variableSymbol,
            constantSymbol:       $constantSymbol,
            specificSymbol:       $specificSymbol,
            amount:               $amount,
            currency:             $currency,
            direction:            $direction,
            message:              $message,
            // CSAS nedává explicit datum zaúčtování v body — Date hlavička mailu je
            // odeslána během minut po platbě, takže ji bereme jako dostatečně přesný posted_at.
            postedAt:             $msg->receivedAt,
        );
    }

    /**
     * HTML → flat text vhodný pro regex extrakci. Webklex HTMLBody již vrací
     * dekódovaný UTF-8 string (quoted-printable rozbalí mime parser),
     * tady jen sundáme tagy, dekódujeme entity a sjednotíme whitespace.
     */
    private function htmlToFlatText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // Strip <style>, <script> i s obsahem (CSAS šablona má dlouhé inline CSS).
        $html = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // Ochrana: některé kódy spojené přímo se sousední literálou ("70&nbsp;Kč") — strip_tags
        // je nehne, ale když mezi <span>y nejsou whitespace, slijí se. Nahradíme tagy mezerou.
        $html = preg_replace('/<[^>]+>/u', ' ', $html) ?? $html;
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // nbsp (U+00A0) → běžná mezera, ať jednotně collapsujeme
        $text = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * "2 500,00" → "2500.00". "0000000112866706" → ponechat (account number normalizér to vyřeší jinde).
     */
    private function normalizeAmount(string $raw): string
    {
        $cleaned = str_replace([' ', "\xC2\xA0"], '', $raw);
        $cleaned = str_replace(',', '.', $cleaned);
        if ($cleaned === '' || !is_numeric($cleaned)) {
            throw new \RuntimeException('CSAS: částku nelze parsovat: ' . $raw);
        }
        return number_format((float) $cleaned, 2, '.', '');
    }

    /** "Kč" → "CZK", ostatní jak jsou (uppercase). */
    private function normalizeCurrency(string $raw): string
    {
        return $raw === 'Kč' ? 'CZK' : strtoupper($raw);
    }

    /**
     * Odstraní leading/trailing whitespace a interní pomlčky/lomítka prefixu („19-2000145399" → ponechá '19-2000145399',
     * AccountNumberNormalizer dořeší jinde — sem chceme jen trim).
     */
    private function normalizeAccount(string $raw): string
    {
        return trim(str_replace(' ', '', $raw));
    }
}
