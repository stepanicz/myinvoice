<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Generuje var. symbol podle template konfigurace v cfg.varsymbol.templates.
 *
 * Counter se atomicky inkrementuje v `invoice_counters` per (supplier_id, invoice_type, period).
 *
 * Placeholdery:
 *   {YYYY} = 4-digit year
 *   {YY}   = 2-digit year
 *   {MM}   = 2-digit month
 *   {C}    = counter padded podle počtu znaků (CCC → 3 znaky 001..999)
 *
 * Příklady:
 *   "{YYYY}{MM}{CCC}"      → "202604001"
 *   "9{YY}{MM}{CCC}"        → "92604001"
 *   "F-{YYYY}/{CCCCCC}"     → "F-2026/000042"
 */
final class VarsymbolGenerator
{
    private const SUPPORTED_TYPES = ['invoice', 'proforma', 'credit_note'];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
    ) {}

    /**
     * Atomicky vygeneruje další var. symbol pro daný typ a datum.
     *
     * @throws \InvalidArgumentException pokud typ nemá template nebo template nemá {C+}
     */
    public function next(int $supplierId, string $invoiceType, ?\DateTimeInterface $for = null): string
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException("Neplatný supplier_id: {$supplierId}");
        }
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Nepodporovaný typ pro varsymbol: {$invoiceType}");
        }

        $template = (string) $this->config->get("varsymbol.templates.{$invoiceType}", '');
        if ($template === '') {
            throw new \InvalidArgumentException("Chybí template pro {$invoiceType} v cfg.varsymbol.templates");
        }

        $for = $for ?? new \DateTimeImmutable('today');
        // CUSTOM (stepanicz): scope counteru — měsíční pokud template obsahuje {MM}, jinak roční.
        // Bez toho by template "{YYYY}{CCC}" v lednu 2027 vyrobil "2027001" duplikát s "2027001" z února.
        $period = str_contains($template, '{MM}')
            ? $for->format('Ym')   // "202604" — měsíční scope
            : $for->format('Y');   // "2026"   — roční scope

        $next = $this->incrementCounter($supplierId, $invoiceType, $period);

        return $this->render($template, $for, $next);
    }

    /**
     * Vrátí, jaký bude další varsymbol BEZ inkrementu (pro náhled v UI).
     */
    public function preview(int $supplierId, string $invoiceType, ?\DateTimeInterface $for = null): string
    {
        if ($supplierId <= 0) return '';
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            return '';
        }
        $template = (string) $this->config->get("varsymbol.templates.{$invoiceType}", '');
        if ($template === '') return '';

        $for = $for ?? new \DateTimeImmutable('today');
        // CUSTOM (stepanicz): viz next() — period scope dle přítomnosti {MM} v template.
        $period = str_contains($template, '{MM}') ? $for->format('Ym') : $for->format('Y');

        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM invoice_counters WHERE supplier_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $invoiceType, $period]);
        $current = (int) ($stmt->fetchColumn() ?: 0);

        return $this->render($template, $for, $current + 1);
    }

    public function render(string $template, \DateTimeInterface $date, int $counter): string
    {
        $vars = [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
        ];
        $rendered = strtr($template, $vars);

        // Counter: matchuj sekvenci {CC...} nebo více znaků
        $rendered = preg_replace_callback('/\{(C+)\}/', function ($m) use ($counter) {
            $len = strlen($m[1]);
            return str_pad((string) $counter, $len, '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;

        return $rendered;
    }

    private function incrementCounter(int $supplierId, string $invoiceType, string $period): int
    {
        $pdo = $this->db->pdo();

        // Atomický INSERT/UPDATE — single statement, žádná race condition
        $stmt = $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, invoice_type, period, last_number)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        );
        $stmt->execute([$supplierId, $invoiceType, $period]);

        $stmt = $pdo->prepare(
            'SELECT last_number FROM invoice_counters WHERE supplier_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $invoiceType, $period]);
        return (int) $stmt->fetchColumn();
    }
}
