<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InvoiceLine;

/**
 * D6 (Oct-2026 invoice review): customer purchase history for fast line
 * entry — which products this customer buys most often.
 */
class CustomerProductHistoryService
{
    /**
     * @return array<int, array{id: int, name: string, sku: ?string, total_quantity: float, times: int, last_sold_at: ?string}>
     */
    public function frequentProducts(int $customerId, int $limit = 10): array
    {
        return InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_lines.product_id')
            ->where('invoices.customer_id', $customerId)
            // Real, non-reversed sales only — drafts and superseded documents
            // must not influence what a customer "usually buys".
            ->whereIn('invoices.status', Customer::PURCHASE_INVOICE_STATUSES)
            ->whereNull('invoices.deleted_at')
            ->whereNotIn('invoices.lifecycle_status', ['superseded', 'voided', 'reversed'])
            ->whereNotNull('invoice_lines.product_id')
            // Only still-sellable products belong on the one-click chips.
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->selectRaw('products.id, products.name, products.sku, SUM(invoice_lines.quantity) as total_quantity, COUNT(*) as times, MAX(invoices.invoice_date) as last_sold_at')
            ->orderByDesc('times')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => $row->sku,
                'total_quantity' => (float) $row->total_quantity,
                'times' => (int) $row->times,
                'last_sold_at' => $row->last_sold_at,
            ])
            ->all();
    }
}
