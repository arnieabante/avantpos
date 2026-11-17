<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * Read-only consistency check of the stock ledger (see StockLedger for the
 * invariants). Runs across all businesses or one, with plain SQL aggregates:
 *
 *  1. balance qty_on_hand = SUM(movements.qty)                  (ledger)
 *  2. every movement's balance_after = running SUM(qty)          (history)
 *  3. SUM(layers.qty_remaining) = qty_on_hand (0 when negative)  (FIFO)
 *  4. balance total_cost = SUM(remaining × unit_cost) in centavos (value)
 *  5. 0 ≤ layer qty_remaining ≤ qty_received                     (layers)
 *  6. layers consumed by an outbound movement ≤ the quantity it issued
 *
 * Every finding is a list entry; an empty list means consistent.
 */
class InventoryVerifier
{
    /**
     * @return list<array{check: string, tenant_id: int, branch_id: int|null, product_variant_id: int|null, detail: string}>
     */
    public function verify(?int $tenantId = null): array
    {
        $issues = [];
        $where = fn (string $alias) => $tenantId ? " AND {$alias}.tenant_id = ".(int) $tenantId : '';

        // 1. Balance vs ledger (also catches movements without a balance row and vice versa).
        $rows = DB::select("
            SELECT k.tenant_id, k.branch_id, k.product_variant_id, b.qty_on_hand, COALESCE(m.total, 0) AS ledger
            FROM (
                SELECT tenant_id, branch_id, product_variant_id FROM inventory_balances
                UNION
                SELECT DISTINCT tenant_id, branch_id, product_variant_id FROM stock_movements
            ) k
            LEFT JOIN inventory_balances b ON b.tenant_id = k.tenant_id AND b.branch_id = k.branch_id AND b.product_variant_id = k.product_variant_id
            LEFT JOIN (
                SELECT tenant_id, branch_id, product_variant_id, SUM(qty) AS total FROM stock_movements GROUP BY tenant_id, branch_id, product_variant_id
            ) m ON m.tenant_id = k.tenant_id AND m.branch_id = k.branch_id AND m.product_variant_id = k.product_variant_id
            WHERE (b.qty_on_hand IS NULL OR b.qty_on_hand <> COALESCE(m.total, 0)){$where('k')}
        ");
        foreach ($rows as $r) {
            $issues[] = $this->issue('ledger', $r, $r->qty_on_hand === null
                ? "movements total {$r->ledger} but no balance row"
                : "on hand {$r->qty_on_hand} but movements total {$r->ledger}");
        }

        // 2. Running balance recorded on each movement.
        $rows = DB::select("
            SELECT * FROM (
                SELECT id, tenant_id, branch_id, product_variant_id, balance_after,
                       SUM(qty) OVER (PARTITION BY tenant_id, branch_id, product_variant_id ORDER BY id) AS running
                FROM stock_movements m WHERE 1 = 1{$where('m')}
            ) x WHERE x.balance_after <> x.running
            LIMIT 500
        ");
        foreach ($rows as $r) {
            $issues[] = $this->issue('history', $r, "movement #{$r->id} says balance {$r->balance_after}, running total is {$r->running}");
        }

        // 3 + 4. Cost layers vs balance quantity and value.
        $rows = DB::select("
            SELECT b.tenant_id, b.branch_id, b.product_variant_id, b.qty_on_hand, b.total_cost,
                   COALESCE(l.remaining, 0) AS remaining, ROUND(COALESCE(l.value, 0), 2) AS value
            FROM inventory_balances b
            LEFT JOIN (
                SELECT tenant_id, branch_id, product_variant_id, SUM(qty_remaining) AS remaining, SUM(qty_remaining * unit_cost) AS value
                FROM inventory_layers WHERE qty_remaining > 0 GROUP BY tenant_id, branch_id, product_variant_id
            ) l ON l.tenant_id = b.tenant_id AND l.branch_id = b.branch_id AND l.product_variant_id = b.product_variant_id
            WHERE (
                   (b.qty_on_hand >= 0 AND COALESCE(l.remaining, 0) <> b.qty_on_hand)
                OR (b.qty_on_hand < 0 AND COALESCE(l.remaining, 0) <> 0)
                OR ROUND(COALESCE(l.value, 0), 2) <> b.total_cost
            ){$where('b')}
        ");
        foreach ($rows as $r) {
            $issues[] = $this->issue('layers', $r, "on hand {$r->qty_on_hand} (value {$r->total_cost}) but layers hold {$r->remaining} (value {$r->value})");
        }

        // 5. Layer bounds.
        $rows = DB::select("
            SELECT id, tenant_id, branch_id, product_variant_id, qty_received, qty_remaining FROM inventory_layers l
            WHERE (l.qty_remaining < 0 OR l.qty_remaining > l.qty_received){$where('l')}
        ");
        foreach ($rows as $r) {
            $issues[] = $this->issue('layer_bounds', $r, "layer #{$r->id}: remaining {$r->qty_remaining} of {$r->qty_received} received");
        }

        // 6. Outbound costing never takes more from layers than was issued.
        $rows = DB::select("
            SELECT m.id, m.tenant_id, m.branch_id, m.product_variant_id, m.qty, SUM(sml.qty) AS taken
            FROM stock_movements m JOIN stock_movement_layers sml ON sml.stock_movement_id = m.id
            WHERE 1 = 1{$where('m')}
            GROUP BY m.id, m.tenant_id, m.branch_id, m.product_variant_id, m.qty
            HAVING m.qty >= 0 OR SUM(sml.qty) > -m.qty
        ");
        foreach ($rows as $r) {
            $issues[] = $this->issue('costing', $r, "movement #{$r->id} (qty {$r->qty}) consumed {$r->taken} from layers");
        }

        return $issues;
    }

    /**
     * @return array{check: string, tenant_id: int, branch_id: int|null, product_variant_id: int|null, detail: string}
     */
    private function issue(string $check, object $row, string $detail): array
    {
        return [
            'check' => $check,
            'tenant_id' => (int) $row->tenant_id,
            'branch_id' => isset($row->branch_id) ? (int) $row->branch_id : null,
            'product_variant_id' => isset($row->product_variant_id) ? (int) $row->product_variant_id : null,
            'detail' => $detail,
        ];
    }
}
