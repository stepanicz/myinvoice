<?php

declare(strict_types=1);

namespace MyInvoice\Service\PaymentMail;

/**
 * DTO pro přijatou IMAP zprávu, používaný napříč ImapClient → Parser → Ingestor.
 * Záměrně nezávislý na webklex/php-imap, ať jdou parsery testovat unit-test.
 */
final class EmailMessage
{
    /**
     * @param int                  $uid              IMAP UID v aktuální složce (UIDVALIDITY-stable)
     * @param string               $messageId        RFC Message-ID (jednoznačný identifikátor mailu)
     * @param list<string>         $routeAddresses   všechny adresy kandidující na alias matching (Delivered-To, X-Original-To,
     *                                              Envelope-To, To: list — každá lowercase, deduped). Používá ingestor pro
     *                                              lookup supplier.payment_email_alias. Důvod pro list: u catch-all schránek
     *                                              je Delivered-To = mailbox (ne alias) a alias je v To.
     * @param string               $fromAddress      email odesílatele (jen mail část, bez display jména)
     * @param string               $subject          dekódovaný subject (UTF-8)
     * @param string               $bodyHtml         dekódované HTML tělo (UTF-8); '' pokud zpráva nemá HTML část
     * @param string               $bodyText         dekódované plain text tělo (UTF-8); '' pokud chybí
     * @param \DateTimeImmutable   $receivedAt       datum z hlavičky Date (server-side, ne klientský čas)
     * @param array<string,string> $rawHeaders       flat dump hlaviček pro audit (klíče lowercase, values trim)
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $messageId,
        public readonly array $routeAddresses,
        public readonly string $fromAddress,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly array $rawHeaders,
    ) {}
}
