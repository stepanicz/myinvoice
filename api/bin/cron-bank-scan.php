<?php

declare(strict_types=1);

/**
 * Auto-import GPC výpisů z konfigurovaného adresáře.
 * Konfigurace v cfg.php:
 *   'bank_import' => ['scan_root' => 'C:/Users/.../FIO/exports']
 *
 * Skenuje rekurzivně root + podadresáře YYYY-MM/ , hledá *.gpc / *.txt.
 * SHA256 dedupe — soubor co už byl naimportovaný se přeskočí.
 */

if (PHP_SAPI !== 'cli' && !defined('CRON_HTTP_AUTHORIZED')) { http_response_code(403); exit("CLI only.\n"); }
require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\StatementScanner;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoiceCalculator;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);

$scanRoot = (string) $config->get('bank_import.scan_root', '');
if ($scanRoot === '' || !is_dir($scanRoot)) {
    fwrite(STDERR, "[bank-scan] cfg.bank_import.scan_root neexistuje: " . ($scanRoot ?: '(prázdné)') . "\n");
    exit(0);
}

$parser       = new GpcParser();
$invoiceRepo  = new InvoiceRepository($conn);
$invoiceCalc  = new InvoiceCalculator($conn);
$finalCreator = new FinalFromProformaCreator($conn, $invoiceRepo, $invoiceCalc);
$matcher      = new StatementMatcher($conn, $finalCreator);
$importer     = new StatementImporter($conn, $parser, $matcher);
$scanner  = new StatementScanner($importer);

$started = microtime(true);
$summary = $scanner->scan($scanRoot);
$ms = (int) ((microtime(true) - $started) * 1000);

echo "[" . date('Y-m-d H:i:s') . "] bank-scan ({$ms} ms): " . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.bank_scan', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);
