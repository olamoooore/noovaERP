<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue #131: detect drift between batch layers and warehouse-level stock.
 *
 * SUM(product_batches.quantity) must equal stock_levels.quantity for every
 * product/warehouse that uses batch tracking. The command is read-only and is
 * intended to run on-demand and from the scheduler.
 */
class ReconcileBatchInventoryCommand extends Command
{
    protected $signature = 'inventory:reconcile-batches
                            {--warehouse= : Limit to a single warehouse id}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Compare SUM(product_batches.quantity) against stock_levels per product/warehouse';

    public function handle(): int
    {
        $warehouseFilter = $this->option('warehouse');

        $sql = '
            WITH batch_totals AS (
                SELECT product_id, warehouse_id, SUM(quantity) AS batch_qty
                FROM product_batches
                WHERE deleted_at IS NULL
                GROUP BY product_id, warehouse_id
            ),
            stock AS (
                SELECT product_id, warehouse_id, quantity AS stock_qty
                FROM stock_levels
            )
            SELECT
                COALESCE(s.product_id, b.product_id) AS product_id,
                COALESCE(s.warehouse_id, b.warehouse_id) AS warehouse_id,
                COALESCE(s.stock_qty, 0) AS stock_qty,
                COALESCE(b.batch_qty, 0) AS batch_qty
            FROM stock s
            FULL OUTER JOIN batch_totals b
                ON s.product_id = b.product_id AND s.warehouse_id = b.warehouse_id
            WHERE COALESCE(s.stock_qty, 0) <> COALESCE(b.batch_qty, 0)
              AND EXISTS (
                  SELECT 1 FROM product_batches pb
                  WHERE pb.product_id = COALESCE(s.product_id, b.product_id)
                    AND pb.deleted_at IS NULL
              )
        ';

        $bindings = [];
        if ($warehouseFilter !== null) {
            $sql .= ' AND COALESCE(s.warehouse_id, b.warehouse_id) = ?';
            $bindings[] = (int) $warehouseFilter;
        }

        $sql .= ' ORDER BY COALESCE(s.product_id, b.product_id), COALESCE(s.warehouse_id, b.warehouse_id)';

        $drift = DB::select($sql, $bindings);

        if ($this->option('json')) {
            $this->line(json_encode([
                'drift_count' => count($drift),
                'rows' => $drift,
            ], JSON_PRETTY_PRINT));
        } else {
            if ($drift === []) {
                $this->info('Batch reconciliation clean: batch layers match stock levels.');
            } else {
                $this->warn(count($drift).' product/warehouse pair(s) with batch-vs-stock drift:');
                $this->table(
                    ['product_id', 'warehouse_id', 'stock_qty', 'batch_qty'],
                    array_map(fn ($row) => [
                        $row->product_id,
                        $row->warehouse_id,
                        $row->stock_qty,
                        $row->batch_qty,
                    ], $drift)
                );
            }
        }

        return $drift === [] ? self::SUCCESS : self::FAILURE;
    }
}
