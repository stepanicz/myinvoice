<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Infrastructure\Config\Config;
use PDO;

/**
 * Generuje per-supplier alias `payment-<10 hex>@<alias_domain>` pro
 * doručování bankovních notifikačních mailů (viz cron-mail-scan).
 *
 * Adresa se při zakládání supplieru nastaví napevno a NEPŘEPISUJE SE —
 * uživatel ji vidí v nastavení a v hostingu si ji nastaví jako alias do
 * společné IMAP schránky `parovaniplateb@…`.
 */
final class PaymentAliasGenerator
{
    public function __construct(private readonly Config $config) {}

    /**
     * Vygeneruje unikátní alias. 10 hex znaků = 16^10 ≈ 1.1×10^12 prostor;
     * kolize je extrémně nepravděpodobná, ale retryujeme pro jistotu.
     *
     * @throws \RuntimeException pokud se po 5 pokusech nepodaří najít unikátní alias
     */
    public function generateUnique(PDO $pdo): string
    {
        $domain = (string) $this->config->get('payment_email_scan.alias_domain', 'uctostepanovi.cz');
        $stmt = $pdo->prepare('SELECT 1 FROM supplier WHERE payment_email_alias = ? LIMIT 1');

        for ($i = 0; $i < 5; $i++) {
            $alias = 'payment-' . bin2hex(random_bytes(5)) . '@' . $domain;
            $stmt->execute([$alias]);
            if ($stmt->fetchColumn() === false) {
                return $alias;
            }
        }
        throw new \RuntimeException('Nepodařilo se vygenerovat unikátní payment_email_alias po 5 pokusech.');
    }
}
