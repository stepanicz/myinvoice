<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail\Parser;

use MyInvoice\Service\PaymentMail\EmailMessage;
use MyInvoice\Service\PaymentMail\ParsedTransaction;

/**
 * Parser notifikačních mailů Fio banky (kód banky 2010).
 *
 * Šablona (From: automat@fio.cz):
 *   - Subject: "Fio banka - prijem na konte" / "Fio banka - vydaj na konte"
 *   - Tělo: plain text, žádné HTML (bodyHtml je prázdné)
 *   - Formát těla:
 *       Příjem na kontě: 2302394278          ← nebo "Výdaj na kontě:"
 *       Částka: 3 630,00
 *       VS: 7060234951
 *       Zpráva příjemci: ÚČETNÍ ŠTĚPÁN       ← jen u příchozích, volitelné
 *       Aktuální zůstatek: 38 815,80
 *       Protiúčet: 131-1909450227/0100       ← nebo text "platba kartou" (bez čísla)
 *       SS: 0
 *       KS: 308
 *   - U odchozích plateb může být navíc: "US:  " (user symbol — neukládáme)
 *   - SS a KS hodnotou "0" FIO signalizuje "neuvedeno" → ukládáme jako null
 */
final class FioParser implements BankParserInterface
{
    public function name(): string
    {
        return 'fio';
    }

    public function supports(EmailMessage $msg): bool
    {
        if (str_ends_with($msg->fromAddress, '@fio.cz')) {
            return true;
        }
        // Záloha: subjekt jako sekundární identifikace (pro případ přeposílání se změněným From).
        if (stripos($msg->subject, 'Fio banka') !== false) {
            return true;
        }
        return false;
    }

    public function parse(EmailMessage $msg): ParsedTransaction
    {
        $text = trim($msg->bodyText);
        if ($text === '') {
            throw new \RuntimeException('FIO: prázdné textové tělo mailu.');
        }

        // Direction + recipient account (první řádek)
        $direction = 'incoming';
        $recipientAccount = '';
        if (preg_match('/^Příjem na kontě:\s*(\d+)/mu', $text, $m)) {
            $direction = 'incoming';
            $recipientAccount = $m[1];
        } elseif (preg_match('/^Výdaj na kontě:\s*(\d+)/mu', $text, $m)) {
            $direction = 'outgoing';
            $recipientAccount = $m[1];
        }

        // Amount — vždy kladné číslo; záporné uděláme sami pro odchozí
        $amount = null;
        if (preg_match('/^Částka:\s*([\d\s\xC2\xA0,\.]+)/mu', $text, $m)) {
            $amount = $this->normalizeAmount(trim($m[1]));
        }
        if ($amount === null) {
            throw new \RuntimeException('FIO: nepodařilo se rozpoznat částku v notifikaci.');
        }
        if ($direction === 'outgoing' && $amount[0] !== '-') {
            $amount = '-' . $amount;
        }

        // Protiúčet: "131-1909450227/0100" nebo text jako "platba kartou"
        $counterpartyAccount = '';
        $counterpartyBankCode = null;
        if (preg_match('/^Protiúčet:\s*(.+)$/mu', $text, $m)) {
            $raw = trim($m[1]);
            if (preg_match('/^([\d\-]+)\/(\d{4})$/', $raw, $pm)) {
                $counterpartyAccount = $pm[1];
                $counterpartyBankCode = $pm[2];
            }
            // jinak textový popis (platba kartou apod.) → necháme prázdné
        }

        // VS — prázdný řetězec → null
        $variableSymbol = null;
        if (preg_match('/^VS:[ \t]*(\S+)/mu', $text, $m)) {
            $variableSymbol = $m[1];
        }

        // KS — "0" u FIO znamená "neuvedeno"
        $constantSymbol = null;
        if (preg_match('/^KS:[ \t]*(\S+)/mu', $text, $m) && $m[1] !== '0') {
            $constantSymbol = $m[1];
        }

        // SS — "0" u FIO znamená "neuvedeno"
        $specificSymbol = null;
        if (preg_match('/^SS:[ \t]*(\S+)/mu', $text, $m) && $m[1] !== '0') {
            $specificSymbol = $m[1];
        }

        // Zpráva příjemci (pouze u příchozích plateb, volitelné pole)
        $message = null;
        if (preg_match('/^Zpráva příjemci:\s*(.+)$/mu', $text, $m)) {
            $raw = trim($m[1]);
            $message = $raw !== '' ? mb_substr($raw, 0, 255) : null;
        }

        return new ParsedTransaction(
            bankCode:             '2010',
            recipientAccount:     $recipientAccount,
            counterpartyAccount:  $counterpartyAccount,
            counterpartyBankCode: $counterpartyBankCode,
            variableSymbol:       $variableSymbol,
            constantSymbol:       $constantSymbol,
            specificSymbol:       $specificSymbol,
            amount:               $amount,
            currency:             'CZK',
            direction:            $direction,
            message:              $message,
            postedAt:             $msg->receivedAt,
        );
    }

    /** "3 630,00" / "1 696,90" → "3630.00" */
    private function normalizeAmount(string $raw): string
    {
        $cleaned = str_replace([' ', "\xC2\xA0"], '', $raw);
        $cleaned = str_replace(',', '.', $cleaned);
        if ($cleaned === '' || !is_numeric($cleaned)) {
            throw new \RuntimeException('FIO: částku nelze parsovat: ' . $raw);
        }
        return number_format((float) $cleaned, 2, '.', '');
    }
}
