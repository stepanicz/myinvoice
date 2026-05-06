<?php

declare(strict_types=1);

/**
 * Cron — denně vystaví faktury z aktivních šablon (recurring_invoice_templates)
 * pokud next_run_date <= dnes.
 *
 * Custom feature — ne v upstreamu radekhulan/myinvoice.
 *
 * Použití:
 *   php api/bin/cron-recurring-invoices.php            # vystaví všechny šablony s next_run_date <= dnes
 *   php api/bin/cron-recurring-invoices.php --dry-run  # jen vypíše, co by se vystavilo
 *   php api/bin/cron-recurring-invoices.php --as-of=2026-06-01  # simuluj jiné datum (testing)
 *
 * Doporučené spouštění: 1× denně okolo 5:00 ráno
 *   crontab: `0 5 * * * docker exec myinvoice-app-1 php /var/www/html/api/bin/cron-recurring-invoices.php >> /var/log/myinvoice-recurring.log 2>&1`
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Recurring\RecurringRunner;

$dryRun = false;
$asOfStr = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (preg_match('/^--as-of=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) { $asOfStr = $m[1]; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$asOf = $asOfStr !== null ? new DateTimeImmutable($asOfStr) : new DateTimeImmutable('today');

$app = Bootstrap::buildApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "Container not available.\n");
    exit(1);
}

/** @var RecurringTemplateRepository $repo */
$repo = $container->get(RecurringTemplateRepository::class);
/** @var RecurringRunner $runner */
$runner = $container->get(RecurringRunner::class);
/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);

$startedAt = microtime(true);
$ids = $repo->dueForRun($asOf);

echo "[" . date('Y-m-d H:i:s') . "] cron-recurring-invoices --as-of=" . $asOf->format('Y-m-d')
    . ($dryRun ? ' --dry-run' : '') . " — found " . count($ids) . " due templates\n";

$report = ['as_of' => $asOf->format('Y-m-d'), 'dry_run' => $dryRun, 'candidates' => count($ids), 'issued' => 0, 'sent' => 0, 'errors' => 0];

if ($dryRun) {
    foreach ($ids as $tplId) {
        $tpl = $repo->find($tplId);
        if ($tpl === null) continue;
        printf(
            "  [DRY] template #%d \"%s\" — %s, next_run=%s, items=%d, auto_send=%s\n",
            $tplId,
            $tpl['name'],
            $tpl['frequency'],
            $tpl['next_run_date'],
            count($tpl['items']),
            $tpl['auto_send'] ? 'yes' : 'no',
        );
    }
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  ({$ms} ms — DRY RUN, nothing issued)\n";
    exit(0);
}

foreach ($ids as $tplId) {
    try {
        $r = $runner->run($tplId, $asOf, null, '', 'cron-recurring-invoices/1.0');
        $report['issued']++;
        if ($r['sent']) $report['sent']++;
        printf(
            "  ✓ template #%d → invoice #%d %s%s, next=%s%s\n",
            $tplId,
            $r['invoice_id'],
            $r['varsymbol'] ?? '(no varsymbol)',
            $r['sent'] ? ' [sent → ' . implode(', ', $r['sent_to']) . ']' : ' [issued only]',
            $r['next_run_date'],
            $r['ended'] ? ' [ENDED]' : '',
        );
    } catch (\Throwable $e) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ template #%d — %s\n", $tplId, $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): issued={$report['issued']}, sent={$report['sent']}, errors={$report['errors']}\n";

$conn->pdo()->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.recurring_invoices', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);
