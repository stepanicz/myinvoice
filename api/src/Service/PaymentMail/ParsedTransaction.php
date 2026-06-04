<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail;

/**
 * Strukturovaný výstup parseru bankovní notifikace.
 *
 * Reprezentuje JEDNU transakci, kterou přidáme jako synthetic řádek
 * do bank_statements (1 mail = 1 statement = 1 transakce).
 */
final class ParsedTransaction
{
    public function __construct(
        /** Kód banky příjemce (4 číslice, např. '0800' pro CSAS). */
        public readonly string $bankCode,
        /** Číslo účtu příjemce (normalizované, bez prefixu / lomítka kódu banky). */
        public readonly string $recipientAccount,
        /** Číslo účtu protistrany (může být '' pokud notifikace nezahrnuje). */
        public readonly string $counterpartyAccount,
        /** Kód banky protistrany (4 číslice) nebo null. */
        public readonly ?string $counterpartyBankCode,
        /** Variabilní symbol (text, jen čísla) — klíč pro matching na fakturu. Null = klient neuvedl VS, transakce se uloží bez párování. */
        public readonly ?string $variableSymbol,
        /** Konstantní symbol nebo null. */
        public readonly ?string $constantSymbol,
        /** Specifický symbol nebo null. */
        public readonly ?string $specificSymbol,
        /** Částka transakce v měně účtu (kladná pro příchozí, záporná pro odchozí). DECIMAL string. */
        public readonly string $amount,
        /** ISO 4217, např. 'CZK'. */
        public readonly string $currency,
        /** 'incoming' = příchozí platba, 'outgoing' = odchozí. */
        public readonly string $direction,
        /** Zpráva pro příjemce / poznámka (může být null). */
        public readonly ?string $message,
        /** Datum zaúčtování (z mailu pokud lze, jinak Date hlavička). */
        public readonly \DateTimeImmutable $postedAt,
    ) {}
}
