<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * CRUD pro šablony pravidelných faktur.
 * Custom feature — ne v upstreamu radekhulan/myinvoice.
 */
final class RecurringTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*,
                    c.company_name AS client_company_name, c.main_email AS client_main_email,
                    p.name AS project_name,
                    cur.code AS currency, cur.symbol AS currency_symbol
               FROM recurring_invoice_templates t
               JOIN clients c ON c.id = t.client_id
          LEFT JOIN projects p ON p.id = t.project_id
               JOIN currencies cur ON cur.id = t.currency_id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = $this->castTemplate($row);
        $row['items'] = $this->itemsFor($id);
        return $row;
    }

    public function listForSupplier(int $supplierId, ?string $status = null): array
    {
        $sql = 'SELECT t.id, t.name, t.client_id, t.project_id, t.invoice_type, t.currency_id,
                       t.frequency, t.start_date, t.end_date, t.next_run_date, t.status,
                       t.auto_send, t.last_run_at, t.last_invoice_id, t.run_count,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       cur.code AS currency
                  FROM recurring_invoice_templates t
                  JOIN clients c ON c.id = t.client_id
             LEFT JOIN projects p ON p.id = t.project_id
                  JOIN currencies cur ON cur.id = t.currency_id
                 WHERE t.supplier_id = ?';
        $params = [$supplierId];
        if ($status !== null) {
            $sql .= ' AND t.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY t.status ASC, t.next_run_date ASC, t.id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => $this->castTemplate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Vrátí všechny aktivní šablony, které mají next_run_date <= dnes (nebo <= zadané datum).
     * Používá cron.
     */
    public function dueForRun(\DateTimeInterface $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM recurring_invoice_templates
              WHERE status = "active" AND next_run_date <= ?
           ORDER BY next_run_date ASC, id ASC'
        );
        $stmt->execute([$asOf->format('Y-m-d')]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function itemsFor(int $templateId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.description, i.quantity, i.unit, i.unit_price_without_vat,
                    i.vat_rate_id, i.order_index,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en,
                    vr.rate_percent AS vat_rate_snapshot
               FROM recurring_invoice_template_items i
               JOIN vat_rates vr ON vr.id = i.vat_rate_id
              WHERE i.template_id = ?
           ORDER BY i.order_index ASC, i.id ASC'
        );
        $stmt->execute([$templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']                     = (int) $r['id'];
            $r['quantity']               = (float) $r['quantity'];
            $r['unit_price_without_vat'] = (float) $r['unit_price_without_vat'];
            $r['vat_rate_id']            = (int) $r['vat_rate_id'];
            $r['vat_rate_snapshot']      = (float) $r['vat_rate_snapshot'];
            $r['order_index']            = (int) $r['order_index'];
        }
        return $rows;
    }

    public function create(int $supplierId, array $data, int $userId): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO recurring_invoice_templates
               (supplier_id, name, client_id, project_id, invoice_type, currency_id, language,
                reverse_charge, payment_due_days, note_above_items, note_below_items,
                frequency, start_date, end_date, next_run_date, status, auto_send, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            (string) $data['name'],
            (int) $data['client_id'],
            !empty($data['project_id']) ? (int) $data['project_id'] : null,
            (string) ($data['invoice_type'] ?? 'invoice'),
            (int) $data['currency_id'],
            (string) ($data['language'] ?? 'cs'),
            !empty($data['reverse_charge']) ? 1 : 0,
            (int) ($data['payment_due_days'] ?? 14),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (string) $data['frequency'],
            (string) $data['start_date'],
            !empty($data['end_date']) ? (string) $data['end_date'] : null,
            (string) ($data['next_run_date'] ?? $data['start_date']),
            (string) ($data['status'] ?? 'active'),
            !empty($data['auto_send']) ? 1 : 0,
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [
            'name', 'client_id', 'project_id', 'invoice_type', 'currency_id', 'language',
            'reverse_charge', 'payment_due_days', 'note_above_items', 'note_below_items',
            'frequency', 'start_date', 'end_date', 'next_run_date', 'status', 'auto_send',
        ];
        $sets = [];
        $params = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f, $data)) {
                continue;
            }
            $sets[] = "$f = ?";
            $val = $data[$f];
            if (in_array($f, ['reverse_charge', 'auto_send'], true)) {
                $params[] = $val ? 1 : 0;
            } elseif (in_array($f, ['client_id', 'project_id', 'currency_id', 'payment_due_days'], true)) {
                $params[] = $val !== null && $val !== '' ? (int) $val : null;
            } else {
                $params[] = $val !== '' ? $val : null;
            }
        }
        if (empty($sets)) {
            return;
        }
        $params[] = $id;
        $sql = 'UPDATE recurring_invoice_templates SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    public function replaceItems(int $templateId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')
                ->execute([$templateId]);

            $stmt = $pdo->prepare(
                'INSERT INTO recurring_invoice_template_items
                   (template_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $idx = 0;
            foreach ($items as $item) {
                $stmt->execute([
                    $templateId,
                    (string) ($item['description'] ?? ''),
                    (float) ($item['quantity'] ?? 1),
                    (string) ($item['unit'] ?? 'ks'),
                    (float) ($item['unit_price_without_vat'] ?? 0),
                    (int) ($item['vat_rate_id'] ?? 0),
                    (int) ($item['order_index'] ?? $idx++),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$id]);
    }

    public function recordRun(int $templateId, int $invoiceId, string $nextRunDate, ?string $newStatus = null): void
    {
        $sql = 'UPDATE recurring_invoice_templates
                   SET last_run_at = NOW(),
                       last_invoice_id = ?,
                       next_run_date = ?,
                       run_count = run_count + 1';
        $params = [$invoiceId, $nextRunDate];
        if ($newStatus !== null) {
            $sql .= ', status = ?';
            $params[] = $newStatus;
        }
        $sql .= ' WHERE id = ?';
        $params[] = $templateId;
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    private function castTemplate(array $row): array
    {
        $intFields = ['id', 'supplier_id', 'client_id', 'project_id', 'currency_id',
                      'payment_due_days', 'last_invoice_id', 'run_count', 'created_by'];
        foreach ($intFields as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = $row[$f] !== null ? (int) $row[$f] : null;
            }
        }
        if (array_key_exists('reverse_charge', $row)) {
            $row['reverse_charge'] = (bool) $row['reverse_charge'];
        }
        if (array_key_exists('auto_send', $row)) {
            $row['auto_send'] = (bool) $row['auto_send'];
        }
        return $row;
    }
}
