<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail\Parser;

use MyInvoice\Service\PaymentMail\EmailMessage;
use MyInvoice\Service\PaymentMail\ParsedTransaction;

/**
 * Per-bank parser bankovních notifikačních mailů. Každá banka má vlastní šablonu
 * (CSAS, Fio, KB, Raiffeisen, …) — registrované parsery se zkouší v pořadí
 * `supports()=true` first wins.
 */
interface BankParserInterface
{
    /** Banka kterou parser zpracovává — pro logging a audit (např. 'csas'). */
    public function name(): string;

    /** Identifikuje, zda mail patří této bance (typicky podle From: nebo z subjectu). */
    public function supports(EmailMessage $msg): bool;

    /** @throws \RuntimeException pokud strukturu nelze rozparsovat. */
    public function parse(EmailMessage $msg): ParsedTransaction;
}
