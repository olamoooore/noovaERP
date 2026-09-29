<?php

namespace Modules\Storefront\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Storefront\Models\StorefrontOrder;

/**
 * GL posting for paid Storefront orders — the per-source posting-service
 * pattern the platform already uses for POS and web orders (all journalizing
 * goes through PostingEngine; no hand-built JournalEntry rows).
 *
 * Deviations from the web-order precedent are deliberate, not incidental:
 *  - legal entity comes from the ORDER record (webhook context has no
 *    request entity attributes);
 *  - stock is NOT deducted here — completion already issued idempotency-keyed
 *    movements via InventoryService (double deduction would be the exact
 *    subledger/ledger drift the reviews keep catching);
 *  - cash lands on the mapped 'cash' account; gateway clearing/fees are the
 *    P3 settlement work (platform defect P-04a) — same starting point the
 *    existing flows have, tracked, not silently ignored.
 */
final class StorefrontPostingService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    public function postPaidOrder(StorefrontOrder $order, float $totalPaid, string $paymentMethod): ?JournalEntry
    {
        if (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true) && $order->status !== 'paid') {
            Log::warning('StorefrontPostingService: refusing to post unpaid order', ['order_id' => $order->id]);

            return null;
        }

        $existing = JournalEntryLink::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', $order->id)
            ->where('relation_type', 'post')
            ->first();

        if ($existing) {
            return JournalEntry::query()->find($existing->journal_entry_id);
        }

        return DB::transaction(function () use ($order, $totalPaid, $paymentMethod) {
            $subtotal = (float) $order->subtotal;
            $bookedTax = round((float) $order->tax_amount, 2);
            $discount = round((float) $order->discount_amount, 2);

            $lines = [
                new PostingRequestLine(
                    accountMappingKey: 'cash',
                    accountId: null,
                    debit: $totalPaid,
                    credit: 0,
                    description: 'Storefront payment received',
                    reference: $order->order_number,
                ),
            ];

            if ($subtotal > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_revenue',
                    accountId: null,
                    debit: 0,
                    credit: $subtotal,
                    description: 'Storefront sales revenue',
                    reference: $order->order_number,
                );
            }

            $taxDetermination = null;
            if ($bookedTax > 0) {
                $taxDetermination = app(\App\Services\TaxDeterminationService::class)->determineForLineSet(
                    documentType: 'storefront_order',
                    documentId: (int) $order->id,
                    date: ($order->paid_at ?? now())->toDateString(),
                    lines: $order->items()->get()->map(fn ($item) => [
                        'source_line_id' => $item->id,
                        'product' => $item->product_id ? \App\Models\Product::query()->find($item->product_id) : null,
                        'base' => round((float) $item->line_total - (float) $item->tax_amount, 2),
                        'rate' => (float) $item->tax_rate,
                        'tax' => (float) $item->tax_amount,
                    ])->values()->all(),
                    direction: 'output',
                    customerId: $order->customer_id,
                );

                foreach (\App\Services\TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], $bookedTax) as $allocation) {
                    if ($allocation['amount'] <= 0) {
                        continue;
                    }
                    $lines[] = new PostingRequestLine(
                        accountMappingKey: 'sales_tax_payable',
                        accountId: null,
                        debit: 0,
                        credit: $allocation['amount'],
                        description: 'Sales tax payable',
                        reference: $order->order_number,
                    );
                }
            }

            if ($discount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_discount',
                    accountId: null,
                    debit: $discount,
                    credit: 0,
                    description: 'Storefront sales discount',
                    reference: $order->order_number,
                );
            }

            $totalCost = 0.0;
            $warehouseId = $order->fulfillment_warehouse_id;
            foreach ($order->items as $item) {
                $product = $item->product_id ? \App\Models\Product::query()->find($item->product_id) : null;
                if ($product !== null && $product->track_inventory) {
                    $totalCost += (float) $product->cost * (float) $item->quantity;
                }
            }

            if ($this->hasTrackedItems($order) && $totalCost <= 0) {
                throw new \RuntimeException('Cannot post a stock-tracked storefront order without cost basis (COGS/inventory lines would be skipped).');
            }

            if ($totalCost > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'cogs',
                    accountId: null,
                    debit: $totalCost,
                    credit: 0,
                    description: 'Cost of goods sold',
                    reference: $order->order_number,
                );
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'inventory',
                    accountId: null,
                    debit: 0,
                    credit: $totalCost,
                    description: 'Inventory reduction',
                    reference: $order->order_number,
                );
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'storefront_order',
                documentId: (int) $order->id,
                postingDate: ($order->paid_at ?? now())->toDateString(),
                description: "Storefront sale ({$paymentMethod})",
                lines: $lines,
                reference: $order->order_number,
                userId: TenantUserHelper::resolveTenantUserId(),
                legalEntityId: $order->legal_entity_id ?? request()?->attributes->get('legal_entity_id'),
                entityBookId: request()?->attributes->get('entity_book_id'),
                taxDeterminationResult: $taxDetermination,
            ));

            if (! $result->success) {
                throw new \RuntimeException("Storefront GL posting failed for {$order->order_number}: {$result->error}");
            }

            return $result->journalEntry ?? JournalEntry::query()->find($result->journalEntryId);
        });
    }

    private function hasTrackedItems(StorefrontOrder $order): bool
    {
        return $order->items()->whereHas('product', fn ($q) => $q->where('track_inventory', true)->where('type', 'product'))->exists();
    }
}
