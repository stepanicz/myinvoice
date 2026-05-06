<?php

declare(strict_types=1);

/**
 * Auto-scan IMAP schránky s bankovními notifikačními maily (CUSTOM stepanicz).
 * Konfigurace v cfg.payment_email_scan (host, user, pass, folder mapping).
 *
 * Multi-supplier routing: každý supplier má vlastní `payment_email_alias`
 * (viz migrace 9001). Aliasy doručují do jediné IMAP schránky, ingestor
 * routuje podle Delivered-To.
 *
 * Manuální spuštění (test):
 *   docker compose exec cron php /var/www/html/api/bin/cron-mail-scan.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\PaymentMail\ImapClient;
use MyInvoice\Service\PaymentMail\Parser\CsasParser;
use MyInvoice\Service\PaymentMail\PaymentMailIngestor;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);

if (!(bool) $config->get('payment_email_scan.enabled', false)) {
    echo "[" . date('Y-m-d H:i:s') . "] mail-scan: disabled v cfg.payment_email_scan.enabled, skip\n";
    exit(0);
}

$logger = new Logger('mail-scan');
$logger->pushHandler(new RotatingFileHandler(
    (string) $config->get('logging.path', $rootDir . '/log/app.log'),
    (int) $config->get('logging.max_files', 90),
    Monolog\Level::Info,
));

$imap          = new ImapClient($config, $logger);
$invoiceRepo   = new InvoiceRepository($conn);
$invoiceCalc   = new InvoiceCalculator($conn);
$finalCreator  = new FinalFromProformaCreator($conn, $invoiceRepo, $invoiceCalc);
$matcher       = new StatementMatcher($conn, $finalCreator);
$csasParser    = new CsasParser();
$ingestor      = new PaymentMailIngestor($config, $conn, $imap, $matcher, $logger, $csasParser);

$started = microtime(true);
try {
    $summary = $ingestor->run();
} catch (\Throwable $e) {
    $logger->error('cron-mail-scan: top-level exception', ['error' => $e->getMessage()]);
    fwrite(STDERR, "[mail-scan] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
$ms = (int) ((microtime(true) - $started) * 1000);

echo "[" . date('Y-m-d H:i:s') . "] mail-scan ({$ms} ms): " . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.mail_scan', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);
