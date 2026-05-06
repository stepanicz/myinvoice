<?php

declare(strict_types=1);

namespace MyInvoice\Service\Recurring;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Vezme šablonu a vystaví novou fakturu.
 * Custom feature — ne v upstreamu.
 *
 * Kroky:
 *   1. Vytvoří draft fakturu (issue_date = dnes, due_date = dnes + payment_due_days)
 *   2. Zkopíruje položky šablony, popisky proženou PlaceholderRendererem
 *   3. Spočítá totals + aplikuje směnný kurz (pokud non-CZK)
 *   4. Pokud auto_send → vystaví + pošle (přes AutoIssueAndSendService)
 *      Pokud nikoliv → jen vystaví (bez emailu)
 *   5. Aktualizuje šablonu (last_invoice_id, next_run_date, run_count, případně status='ended')
 *
 * @phpstan-type RunResult array{
 *   invoice_id: int, varsymbol: string|null, sent_to: list<string>, sent: bool,
 *   next_run_date: string, ended: bool
 * }
 */
final class RecurringRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly RecurringTemplateRepository $templates,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoicePdfRenderer $pdfRenderer,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
        private readonly AutoIssueAndSendService $autoIssueAndSend,
        private readonly PlaceholderRenderer $placeholders,
    ) {}

    /**
     * @return array{invoice_id: int, varsymbol: string|null, sent_to: list<string>, sent: bool, next_run_date: string, ended: bool}
     */
    public function run(int $templateId, ?\DateTimeInterface $issueDateOverride = null, ?int $userId = null, string $ip = '', string $ua = 'recurring-runner'): array
    {
        $tpl = $this->templates->find($templateId);
        if ($tpl === null) {
            throw new \RuntimeException("Recurring template #$templateId not found");
        }

        $issueDate = $issueDateOverride !== null
            ? \DateTimeImmutable::createFromInterface($issueDateOverride)
            : new \DateTimeImmutable('today');
        $issueDateStr = $issueDate->format('Y-m-d');
        $dueDays = (int) ($tpl['payment_due_days'] ?? 14);
        $dueDate = $issueDate->modify("+{$dueDays} days")->format('Y-m-d');
        $type = (string) ($tpl['invoice_type'] ?? 'invoice');
        $taxDate = $type === 'proforma' ? null : $issueDateStr;
        $createdBy = $userId ?? (int) $tpl['created_by'];

        $newInvoiceId = $this->createDraftFromTemplate($tpl, $issueDateStr, $taxDate, $dueDate, $type, $createdBy);

        $this->calc->recompute($newInvoiceId);
        $this->rateApplier->applyToInvoice($newInvoiceId);

        $sentTo = [];
        $sent = false;
        $varsymbol = null;

        if (!empty($tpl['auto_send'])) {
            $result = $this->autoIssueAndSend->run($newInvoiceId, $userId, $ip, $ua);
            $sentTo    = $result['sent_to'];
            $varsymbol = $result['varsymbol'];
            $sent      = !empty($sentTo);
        } else {
            $varsymbol = $this->issueOnly($newInvoiceId, $userId, $ip, $ua);
        }

        // Posun next_run_date a případně ended
        $next = $this->placeholders->advanceRunDate(
            new \DateTimeImmutable((string) $tpl['next_run_date']),
            (string) $tpl['frequency'],
        );
        $endDate = !empty($tpl['end_date']) ? new \DateTimeImmutable((string) $tpl['end_date']) : null;
        $ended = $endDate !== null && $next > $endDate;
        $newStatus = $ended ? 'ended' : null;

        $this->templates->recordRun(
            $templateId,
            $newInvoiceId,
            $next->format('Y-m-d'),
            $newStatus,
        );

        $this->logger->log('recurring.run', $userId, 'recurring_template', $templateId, [
            'invoice_id'   => $newInvoiceId,
            'varsymbol'    => $varsymbol,
            'sent'         => $sent,
            'sent_to'      => $sentTo,
            'next_run'     => $next->format('Y-m-d'),
            'ended'        => $ended,
        ], $ip, $ua);

        return [
            'invoice_id'    => $newInvoiceId,
            'varsymbol'     => $varsymbol,
            'sent_to'       => $sentTo,
            'sent'          => $sent,
            'next_run_date' => $next->format('Y-m-d'),
            'ended'         => $ended,
        ];
    }

    private function createDraftFromTemplate(array $tpl, string $issueDate, ?string $taxDate, string $dueDate, string $type, int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, client_id, project_id, supplier_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, language,
                    note_above_items, note_below_items, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $type,
                (int) $tpl['client_id'],
                !empty($tpl['project_id']) ? (int) $tpl['project_id'] : null,
                (int) $tpl['supplier_id'],
                $issueDate,
                $taxDate,
                $dueDate,
                (int) $tpl['currency_id'],
                !empty($tpl['reverse_charge']) ? 1 : 0,
                (string) ($tpl['language'] ?? 'cs'),
                $this->placeholders->render(
                    (string) ($tpl['note_above_items'] ?? ''),
                    (string) $tpl['frequency'],
                    new \DateTimeImmutable($issueDate),
                    (string) ($tpl['language'] ?? 'cs'),
                ) ?: null,
                $this->placeholders->render(
                    (string) ($tpl['note_below_items'] ?? ''),
                    (string) $tpl['frequency'],
                    new \DateTimeImmutable($issueDate),
                    (string) ($tpl['language'] ?? 'cs'),
                ) ?: null,
                $userId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
            );
            foreach ($tpl['items'] as $item) {
                $description = $this->placeholders->render(
                    (string) $item['description'],
                    (string) $tpl['frequency'],
                    new \DateTimeImmutable($issueDate),
                    (string) ($tpl['language'] ?? 'cs'),
                );
                $itemStmt->execute([
                    $newId,
                    $description,
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                ]);
            }

            $pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Vystaví draft (varsymbol + snapshots + status='issued') BEZ posílání emailu.
     * Stejná logika jako prefix v AutoIssueAndSendService::run() když auto_send=1, ale bez mail/PDF kroků.
     * Záměrně inline (nedotýkáme se core souborů kvůli upstream merge).
     */
    private function issueOnly(int $invoiceId, ?int $userId, string $ip, string $ua): ?string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || $invoice['status'] !== 'draft') {
            return $invoice['varsymbol'] ?? null;
        }

        $issueDate = new \DateTimeImmutable($invoice['issue_date']);
        $supplierId = (int) $invoice['supplier_id'];
        $varsymbol = $this->varsymbol->next($supplierId, $invoice['invoice_type'], $issueDate);
        $snapshots = $this->snapshots->build(
            (int) $invoice['client_id'],
            (int) $invoice['currency_id'],
            $supplierId,
        );
        $stmt = $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = "issued"
             WHERE id = ? AND status = "draft"'
        );
        $stmt->execute([
            $varsymbol,
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);

        $this->logger->log('invoice.issued', $userId, 'invoice', $invoiceId, [
            'varsymbol'   => $varsymbol,
            'auto_reason' => 'recurring_template',
        ], $ip, $ua);
        $this->stats->recomputeForInvoiceId($invoiceId);
        $this->pdfRenderer->invalidate($invoiceId, 'invalidate_issue');

        return $varsymbol;
    }
}
