<?php

namespace Modules\FinancialReporting\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1020: the client's "Sales Records" spreadsheet reproduced as a report.
 *
 * One row per invoice (customer, date, invoice number, lot, monthly totals)
 * with Qty/S$ column pairs for the selected dimension — By item code, By
 * brand, By supplier, By customer or By category. Qty includes FOC units;
 * S$ is the net line value excluding tax (sheet sample 2026/05/020: paid 5
 * + FOC 1 -> Qty 6, S$ 5000 where the ERP line total is 5450 incl. 9% GST).
 */
class SalesRecordsService
{
    private const GROUP_BY = ['item_code', 'brand', 'supplier', 'customer', 'category'];

    public function generate(array $filters): array
    {
        $start = Carbon::parse($filters['start_date'] ?? now()->startOfYear()->toDateString())->startOfDay();
        $end = Carbon::parse($filters['end_date'] ?? now()->toDateString())->endOfDay();
        $groupBy = in_array($filters['group_by'] ?? null, self::GROUP_BY, true)
            ? $filters['group_by']
            : 'item_code';

        $hasSuppliers = Schema::hasTable('product_suppliers') && Schema::hasTable('suppliers');

        $supplierExpr = $hasSuppliers
            ? '(SELECT s.name FROM product_suppliers ps JOIN suppliers s ON s.id = ps.supplier_id'
                .' WHERE ps.product_id = il.product_id'
                .' ORDER BY ps.is_preferred DESC, ps.priority ASC NULLS LAST, ps.id ASC LIMIT 1)'
            : 'NULL';

        $groupKeyExpr = match ($groupBy) {
            'brand' => "COALESCE(b.name, 'No brand')",
            'supplier' => "COALESCE($supplierExpr, 'No supplier')",
            'customer' => "COALESCE(c.name, 'Walk-in Customer')",
            'category' => "COALESCE(pcat.name, 'Uncategorised')",
            default => "COALESCE(p.sku, '—')",
        };

        // #1020: line_total carries the tax even when tax_inclusive is false
        // (verified on the Acme snapshot: 5 × 1000 line_total 5450,
        // tax_amount 450) — the sheet's S$ is the net paid value.
        $netValueExpr = 'GREATEST(il.line_total - COALESCE(il.tax_amount, 0), 0)';

        $query = DB::table('invoice_lines as il')
            ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
            ->leftJoin('products as p', 'p.id', '=', 'il.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
            ->leftJoin('product_categories as pcat', 'pcat.id', '=', 'p.category_id')
            ->whereBetween('i.invoice_date', [$start->toDateString(), $end->toDateString()])
            // #1020: drafts and voids are not sales records.
            ->whereNotIn('i.status', ['draft', 'cancelled', 'void'])
            ->where(function ($q) {
                $q->whereNull('il.line_type')->orWhere('il.line_type', 'product');
            });

        $this->applyFilters($query, $filters, $hasSuppliers);

        $lines = $query->select([
            'i.id as invoice_id',
            'i.invoice_number',
            'i.invoice_date',
            'i.due_date',
            'i.paid_amount',
            'i.balance',
            'i.settlement_status',
            'i.status as invoice_status',
            'c.name as customer_name',
            'il.product_id',
            'p.name as product_name',
            'p.sku as product_sku',
            'p.short_description as product_short_description',
            'b.name as brand_name',
            DB::raw($groupKeyExpr.' as group_key'),
            DB::raw('COALESCE(il.quantity, 0) as quantity'),
            DB::raw($netValueExpr.' as net_value'),
            'il.lot_number',
        ])
            ->orderBy('i.invoice_date')
            ->orderBy('i.id')
            ->orderBy('il.id')
            ->get();

        $invoiceIds = $lines->pluck('invoice_id')->unique()->values()->all();
        $payments = $this->latestPayments($invoiceIds);
        $bonusSchemes = $this->bonusSchemeLabels($invoiceIds);

        $rows = [];
        $months = [];
        $activityColumns = [];
        $summary = ['total_quantity' => 0.0, 'total_value' => 0.0, 'invoice_count' => count($invoiceIds)];

        foreach ($lines as $line) {
            $invoiceId = (int) $line->invoice_id;
            $key = (string) $line->group_key;
            $quantity = (float) $line->quantity;
            $value = (float) $line->net_value;
            $month = substr((string) $line->invoice_date, 0, 7);

            if (! isset($rows[$invoiceId])) {
                $rows[$invoiceId] = [
                    'invoice_id' => $invoiceId,
                    'customer_name' => $line->customer_name ?? 'Walk-in Customer',
                    'date' => $line->invoice_date,
                    'month' => $month,
                    'document_number' => $line->invoice_number,
                    'lot_number' => '',
                    'lots' => [],
                    'bonus_scheme' => $bonusSchemes[$invoiceId] ?? null,
                    'due_date' => $line->due_date,
                    'status' => $this->statusText($line, $payments[$invoiceId] ?? null),
                    'values' => [],
                    'total' => 0.0,
                ];
            }

            if ($line->lot_number) {
                foreach (preg_split('/\s*,\s*/', (string) $line->lot_number) ?: [] as $lot) {
                    if ($lot !== '' && ! in_array($lot, $rows[$invoiceId]['lots'], true)) {
                        $rows[$invoiceId]['lots'][] = $lot;
                    }
                }
            }

            $rows[$invoiceId]['values'][$key] ??= ['quantity' => 0.0, 'value' => 0.0];
            $rows[$invoiceId]['values'][$key]['quantity'] += $quantity;
            $rows[$invoiceId]['values'][$key]['value'] += $value;
            $rows[$invoiceId]['total'] += $value;

            $months[$month] ??= ['month' => $month, 'values' => [], 'total' => 0.0];
            $months[$month]['values'][$key] ??= ['quantity' => 0.0, 'value' => 0.0];
            $months[$month]['values'][$key]['quantity'] += $quantity;
            $months[$month]['values'][$key]['value'] += $value;
            $months[$month]['total'] += $value;

            $activityColumns[$key] ??= [
                'key' => $key,
                'label' => $groupBy === 'item_code'
                    ? ($line->product_name ?? $line->product_sku ?? $key)
                    : $key,
                'code' => $line->product_sku,
                'short_description' => $line->product_short_description,
            ];

            $summary['total_quantity'] += $quantity;
            $summary['total_value'] += $value;
        }

        foreach ($rows as &$row) {
            $row['lot_number'] = implode(', ', $row['lots']);
            unset($row['lots']);
        }
        unset($row);

        $columns = $this->buildColumns($groupBy, $activityColumns, $filters, $hasSuppliers);

        foreach ($months as &$month) {
            $month['label'] = Carbon::parse($month['month'].'-01')->format('F Y');
        }
        unset($month);

        ksort($months);

        return [
            'title' => 'Sales Records',
            'period' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'group_by' => $groupBy,
            'columns' => array_values($columns),
            'rows' => array_values($rows),
            'month_totals' => array_values($months),
            'summary' => [
                'total_quantity' => round($summary['total_quantity'], 4),
                'total_value' => round($summary['total_value'], 2),
                'invoice_count' => $summary['invoice_count'],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param \Illuminate\Database\Query\Builder $query */
    private function applyFilters($query, array $filters, bool $hasSuppliers): void
    {
        if (! empty($filters['brand_id'])) {
            $query->where('p.brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('p.category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('i.customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['product_id'])) {
            $query->where('il.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['item_code'])) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['item_code']);
            $query->where('p.sku', 'ILIKE', '%'.$escaped.'%');
        }
        if (! empty($filters['supplier_id']) && $hasSuppliers) {
            $supplierId = (int) $filters['supplier_id'];
            $query->whereExists(function ($sub) use ($supplierId) {
                $sub->select(DB::raw(1))
                    ->from('product_suppliers as ps2')
                    ->whereColumn('ps2.product_id', 'il.product_id')
                    ->where('ps2.supplier_id', $supplierId);
            });
        }
    }

    /**
     * Columns are the products in scope (so a brand's catalogue shows even if a
     * product has no sales in the period) merged with the keys that have rows.
     */
    private function buildColumns(string $groupBy, array $activityColumns, array $filters, bool $hasSuppliers): array
    {
        $columns = $activityColumns;

        $hasProductScope = ! empty($filters['brand_id'])
            || ! empty($filters['category_id'])
            || ! empty($filters['supplier_id'])
            || ! empty($filters['item_code'])
            || ! empty($filters['product_id']);

        if ($groupBy === 'item_code' && $hasProductScope) {
            $products = DB::table('products as p')
                ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                ->where('p.is_active', true)
                ->when(! empty($filters['brand_id']), fn ($q) => $q->where('p.brand_id', (int) $filters['brand_id']))
                ->when(! empty($filters['category_id']), fn ($q) => $q->where('p.category_id', (int) $filters['category_id']))
                ->when(! empty($filters['product_id']), fn ($q) => $q->where('p.id', (int) $filters['product_id']))
                ->when(! empty($filters['item_code']), function ($q) use ($filters) {
                    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['item_code']);
                    $q->where('p.sku', 'ILIKE', '%'.$escaped.'%');
                })
                ->when(! empty($filters['supplier_id']) && $hasSuppliers, function ($q) use ($filters) {
                    $supplierId = (int) $filters['supplier_id'];
                    $q->whereExists(function ($sub) use ($supplierId) {
                        $sub->select(DB::raw(1))
                            ->from('product_suppliers as ps3')
                            ->whereColumn('ps3.product_id', 'p.id')
                            ->where('ps3.supplier_id', $supplierId);
                    });
                })
                ->select('p.sku', 'p.name', 'p.short_description')
                ->orderBy('p.sku')
                ->get();

            foreach ($products as $product) {
                if (! $product->sku || isset($columns[$product->sku])) {
                    continue;
                }
                $columns[$product->sku] = [
                    'key' => $product->sku,
                    'label' => $product->name ?? $product->sku,
                    'code' => $product->sku,
                    'short_description' => $product->short_description,
                ];
            }
        }

        $ordered = array_values($columns);
        usort($ordered, function (array $a, array $b) use ($groupBy) {
            $keyA = $groupBy === 'item_code' ? ($a['code'] ?? $a['key']) : $a['key'];
            $keyB = $groupBy === 'item_code' ? ($b['code'] ?? $b['key']) : $b['key'];

            return strcasecmp((string) $keyA, (string) $keyB);
        });

        return $ordered;
    }

    /** @return array<int, object> latest non-reversed payment per invoice */
    private function latestPayments(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return DB::table('payments')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('payment_status', '!=', 'reversed')
            ->whereNull('deleted_at')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get(['invoice_id', 'payment_date', 'payment_status'])
            ->groupBy('invoice_id')
            ->map(fn (Collection $payments) => $payments->first())
            ->all();
    }

    /** @return array<int, string> "5 + 1" style labels per invoice */
    private function bonusSchemeLabels(array $invoiceIds): array
    {
        if ($invoiceIds === [] || ! Schema::hasTable('bonus_scheme_usages')) {
            return [];
        }

        return DB::table('bonus_scheme_usages as bsu')
            ->join('invoice_lines as bil', 'bil.id', '=', 'bsu.invoice_item_id')
            ->whereIn('bil.invoice_id', $invoiceIds)
            ->where('bsu.status', '!=', 'reversed')
            ->groupBy('bil.invoice_id')
            ->selectRaw('bil.invoice_id, SUM(bsu.qualifying_qty) as qualifying, SUM(bsu.foc_qty) as foc')
            ->get()
            ->mapWithKeys(function ($row) {
                $qualifying = (int) round((float) $row->qualifying);
                $foc = (int) round((float) $row->foc);

                return [(int) $row->invoice_id => $qualifying.' + '.$foc];
            })
            ->all();
    }

    private function statusText(object $line, ?object $latestPayment): string
    {
        $balance = (float) ($line->balance ?? 0);
        $paid = (float) ($line->paid_amount ?? 0);
        $settlement = (string) ($line->settlement_status ?? '');

        if ($settlement === 'paid' || $balance <= 0.01) {
            $status = 'Completed';
        } elseif ($paid > 0) {
            $status = 'Partial';
        } else {
            $status = ucfirst($settlement !== '' ? $settlement : (string) $line->invoice_status);
        }

        if ($latestPayment?->payment_date) {
            return Carbon::parse($latestPayment->payment_date)->format('j M Y').', '.$status;
        }

        return $status;
    }
}
