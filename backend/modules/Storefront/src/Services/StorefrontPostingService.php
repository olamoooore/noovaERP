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

    public function postPaidOrder(StorefrontOrder $order, float $totalPaid, string $paymentMethod, string $receiptMappingKey = 'cash'): ?JournalEntry
    {
        if (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true) && $order->status !== 'paid') {
            Log::warning('StorefrontPostingService: refusing to post unpaid order', ['order_id' => $order->id]);

            return null;
        }

        if (! in_array($receiptMappingKey, ['cash', 'gateway_receivable'], true)) {
            throw new \InvalidArgumentException("Unsupported storefront receipt account '{$receiptMappingKey}'.");
        }

        $existing = JournalEntryLink::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', $order->id)
            ->where('relation_type', 'post')
            ->first();

        if ($existing) {
            return JournalEntry::query()->find($existing->journal_entry_id);
        }

        return DB::transaction(function () use ($order, $totalPaid, $paymentMethod, $receiptMappingKey) {
            $subtotal = (float) $order->subtotal;
            $bookedTax = round((float) $order->tax_amount, 2);
            $discount = round((float) $order->discount_amount, 2);

            $lines = [
                new PostingRequestLine(
                    accountMappingKey: $receiptMappingKey,
                    accountId: null,
                    debit: $totalPaid,
                    credit: 0,
                    description: $receiptMappingKey === 'gateway_receivable'
                        ? 'Storefront payment held by gateway'
                        : 'Storefront payment received',
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

    /**
     * Refund reversal entry (FR-PAY-05 / A-18): the exact cash-side mirror of
     * the combined sale posting — DR sales revenue and DR sales tax payable
     * (pro-rata share of the booked totals), CR cash for the refunded amount.
     * Stock is NOT restored here: refunds move money; physical returns are
     * the P4 returns/credit-note layer (FR-ORD-06).
     *
     * Idempotency is per refund record: the journal link keys on
     * (storefront_refund, refund row id), so replaying the SAME refund posts
     * nothing a second time while distinct partial refunds each get their own
     * single entry.
     */
    public function postRefundPayment(StorefrontOrder $order, int $refundId, float $amount, string $payoutMappingKey = 'cash'): JournalEntry
    {
        if (! in_array($payoutMappingKey, ['cash', 'gateway_receivable'], true)) {
            throw new \InvalidArgumentException("Unsupported refund payout account '{$payoutMappingKey}'.");
        }

        $existing = JournalEntryLink::query()
            ->where('source_document_type', 'storefront_refund')
            ->where('source_document_id', $refundId)
            ->where('relation_type', 'post')
            ->first();

        if ($existing) {
            $entry = JournalEntry::query()->find($existing->journal_entry_id);
            if ($entry !== null) {
                return $entry;
            }
        }

        $amount = round($amount, 2);
        $total = round((float) $order->total, 2);
        if ($amount <= 0 || $total <= 0 || $amount > $total + 0.01) {
            throw new \RuntimeException("Refund amount {$amount} is not valid for order total {$total}.");
        }

        $taxShare = min(round((float) $order->tax_amount * ($amount / $total), 2), $amount);
        $revenueShare = round($amount - $taxShare, 2);

        $lines = [];
        if ($revenueShare > 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: $revenueShare,
                credit: 0,
                description: 'Storefront refund — revenue reduction',
                reference: $order->order_number,
            );
        }
        if ($taxShare > 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_tax_payable',
                accountId: null,
                debit: $taxShare,
                credit: 0,
                description: 'Storefront refund — booked tax reduction',
                reference: $order->order_number,
            );
        }
        $lines[] = new PostingRequestLine(
            accountMappingKey: $payoutMappingKey,
            accountId: null,
            debit: 0,
            credit: $amount,
            description: $payoutMappingKey === 'gateway_receivable'
                ? 'Storefront refund claimed against unsettled gateway balance'
                : 'Storefront refund paid out',
            reference: $order->order_number,
        );

        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'storefront_refund',
            documentId: $refundId,
            postingDate: now()->toDateString(),
            description: "Storefront refund for {$order->order_number} ({$amount} {$order->currency})",
            lines: $lines,
            reference: "REFUND-{$order->order_number}-{$refundId}",
            userId: TenantUserHelper::resolveTenantUserId(),
            legalEntityId: $order->legal_entity_id ?? request()?->attributes->get('legal_entity_id'),
            entityBookId: request()?->attributes->get('entity_book_id'),
        ));

        if (! $result->success) {
            throw new \RuntimeException("Storefront refund GL posting failed for {$order->order_number}: {$result->error}");
        }

        return $result->journalEntry ?? JournalEntry::query()->find($result->journalEntryId);
    }

    private function hasTrackedItems(StorefrontOrder $order): bool
    {
        return $order->items()->whereHas('product', fn ($q) => $q->where('track_inventory', true)->where('type', 'product'))->exists();
    }
}
