<?php

declare(strict_types=1);

/**
 * HTTP cron dispatcher pro svethostingu (crony se spouští přes URL, ne CLI).
 *
 * Použití (v administraci hostingu nastav URL s rozvrhem):
 *   https://fakturace.uctostepanovi.cz/cron-trigger.php?key=KLIC&job=mail-scan
 *
 * Volitelné argumenty pro skript:
 *   ...&job=reminders&args=--days=5,--dry-run
 *
 * Klíč je v cfg.php → cron.http_key. Skripty v api/bin/cron-*.php jsou psané
 * pro CLI SAPI; tady emulujeme STDERR/STDOUT/$argv a povolíme jejich CLI guard
 * přes konstantu CRON_HTTP_AUTHORIZED.
 */

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$rootDir = __DIR__;

$cfg = is_file($rootDir . '/cfg.php') ? require $rootDir . '/cfg.php' : null;
$expectedKey = is_array($cfg) ? (string) ($cfg['cron']['http_key'] ?? '') : '';

$key = (string) ($_GET['key'] ?? '');
if ($expectedKey === '' || !hash_equals($expectedKey, $key)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$jobs = [
    'mail-scan'          => 'api/bin/cron-mail-scan.php',
    'bank-scan'          => 'api/bin/cron-bank-scan.php',
    'recurring'          => 'api/bin/cron-recurring-invoices.php',
    'reminders'          => 'api/bin/cron-send-reminders.php',
    'approval-reminders' => 'api/bin/cron-send-approval-reminders.php',
    'cleanup'            => 'api/bin/cron-cleanup.php',
    'backup'             => 'api/bin/cron-backup.php',
    'backup-pdf'         => 'api/bin/cron-backup-pdf.php',
];

$job = (string) ($_GET['job'] ?? '');
if (!isset($jobs[$job])) {
    http_response_code(400);
    echo "Unknown job '" . htmlspecialchars($job, ENT_QUOTES) . "'. Available:\n";
    foreach (array_keys($jobs) as $j) {
        echo "  - $j\n";
    }
    exit;
}

$script = $rootDir . '/' . $jobs[$job];
if (!is_file($script)) {
    http_response_code(500);
    exit("Script not found: {$jobs[$job]}\n");
}

// --- CLI emulace, aby skripty psané pro `php` SAPI běžely i pod HTTP ---
if (!defined('STDIN'))  { define('STDIN',  fopen('php://input', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://output', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://output', 'w')); }

// $argv pro skripty, které parsují přepínače (--days=, --dry-run, …)
$extra = [];
if (isset($_GET['args']) && $_GET['args'] !== '') {
    foreach (explode(',', (string) $_GET['args']) as $a) {
        $a = trim($a);
        if ($a !== '') {
            $extra[] = $a;
        }
    }
}
$argv = array_merge([$jobs[$job]], $extra);
$argc = count($argv);
$GLOBALS['argv'] = $argv;
$GLOBALS['argc'] = $argc;

@set_time_limit(0);
ignore_user_abort(true);
while (ob_get_level() > 0) { ob_end_flush(); }

define('CRON_HTTP_AUTHORIZED', true);

echo "[cron-trigger] job={$job} start " . date('Y-m-d H:i:s') . "\n";
$started = microtime(true);

require $script;

printf("\n[cron-trigger] job=%s done in %.1fs %s\n", $job, microtime(true) - $started, date('Y-m-d H:i:s'));
