<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přepočítá sumy faktury (totals + per-item) a vat breakdown.
 *
 * Pravidla:
 *  - Per item: total_without_vat = round(quantity * unit_price, 2)
 *              total_vat = round(total_without_vat * (vat_rate_snapshot / 100), 2)
 *              total_with_vat = total_without_vat + total_vat
 *  - Reverse charge: items se vat_rate_snapshot = 0 (i kdyby byl jiný)
 *  - Faktura: SUM jednotlivých položek
 *  - amount_to_pay je generated column v DB (total_with_vat - advance_paid_amount)
 */
final class InvoiceCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočítá faktury sumy a per-item totals. Volá se po každém edit/insert.
     *
     * @return array{totals:array, vat_breakdown:array}
     */
    public function recompute(int $invoiceId): array
    {
        $pdo = $this->db->pdo();

        // Načti hlavičku + supplier.is_vat_payer
        // CUSTOM (stepanicz): neplátce DPH = vynucené 0% rate (analogie reverse_charge).
        // Bez tohoto se 21% vat_rate_snapshot z položek započítával i u neplátců.
        $stmt = $pdo->prepare(
            'SELECT i.reverse_charge, s.is_vat_payer
               FROM invoices i
               JOIN supplier s ON s.id = i.supplier_id
              WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            throw new \RuntimeException("Invoice {$invoiceId} not found");
        }
        $reverseCharge = (bool) $header['reverse_charge'] || !((bool) $header['is_vat_payer']);

        // Načti položky
        $stmt = $pdo->prepare(
            'SELECT id, quantity, unit_price_without_vat, vat_rate_snapshot
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Vlastní výpočty delegujeme na pure-function InvoiceMath (testovatelné bez DB).
        $computed = InvoiceMath::compute($items, $reverseCharge);

        // Persist per-item totals
        $updateItem = $pdo->prepare(
            'UPDATE invoice_items SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
        );
        foreach ($items as $i => $item) {
            $r = $computed['items'][$i];
            $updateItem->execute([$r['base'], $r['vat'], $r['with'], (int) $item['id']]);
        }

        // Persist invoice totals (amount_to_pay je generated column)
        $stmt = $pdo->prepare(
            'UPDATE invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = 0
             WHERE id = ?'
        );
        $stmt->execute([
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $invoiceId,
        ]);

        return [
            'totals'        => $computed['totals'],
            'vat_breakdown' => $computed['vat_breakdown'],
        ];
    }
}
