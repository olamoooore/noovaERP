<?php

namespace Modules\POS\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\POS\Models\PosOrder;

class PosPostingService
{
    private const MISSING_COST_BASIS_PREFIX = 'Cannot post stock-tracked POS order without cost basis';

    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    public function postPaidOrder(PosOrder $order, float $totalPaid, string $paymentMethod): JournalEntry
    {
        $lines = [];

        // MRD FR-9.1: a foreign POS order posts BASE amounts at the order's
        // snapshot rate; the receipt-facing columns keep the document figures
        // (currency_code=base, original_amount=doc, exchange_rate=snapshot).
        $baseCurrency = strtoupper((string) ($order->base_currency_code
            ?: \App\Services\DocumentCurrencyResolver::baseFor(null, $order->customer_id)));
        $docCurrency = strtoupper((string) ($order->currency ?: $baseCurrency));
        $rate = (float) ($order->exchange_rate ?? 1.0);
        $foreign = $docCurrency !== $baseCurrency && $rate > 0;
        $toBase = static fn (float $docAmount): float => $foreign
            ? round($docAmount * $rate, 2)
            : round($docAmount, 2);

        // DR Cash (at the order snapshot rate when foreign)
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'cash',
            accountId: null,
            debit: $toBase($totalPaid),
            credit: 0,
            description: 'POS payment received',
            reference: $order->order_number,
            currencyCode: $foreign ? $baseCurrency : null,
            originalAmount: $foreign ? round($totalPaid, 2) : null,
            exchangeRate: $foreign ? $rate : null,
        );

        // CR Sales Revenue — for foreign orders the revenue leg is emitted
        // AFTER tax so it can absorb the ≤0.01-per-leg conversion residual
        // and keep the entry exactly balanced (same §9.4 rule as invoices).
        $revenueBase = $toBase((float) $order->subtotal);
        $revenueOriginal = round((float) $order->subtotal, 2);
        $emitRevenue = (float) $order->subtotal > 0;

        // CR Sales Tax — MRD TAX-REQ-07 (D2): build a canonical, persisted
        // TaxDeterminationResult from the booked item tax and pass it into the
        // posting so the TaxGate passes, tax_transaction_details are written,
        // and POS output tax lands on the statutory return. One credit line
        // per determined tax_code; the booked order tax total is preserved
        // exactly (balance-safe allocation).
        $taxDetermination = null;
        $bookedTaxTotal = round((float) $order->tax_amount, 2);
        $taxBaseTotal = 0.0;

        if ($bookedTaxTotal > 0) {
            $determinationService = app(\App\Services\TaxDeterminationService::class);

            $taxDetermination = $determinationService->determineForLineSet(
                documentType: 'pos_order',
                documentId: (int) $order->id,
                date: ($order->paid_at ?? now())->toDateString(),
                lines: $order->items->map(fn ($item) => [
                    'source_line_id' => $item->id,
                    'product' => $item->variant?->product ?? $item->product,
                    'base' => round((float) $item->line_total - (float) $item->tax_amount, 2),
                    'rate' => (float) $item->tax_rate,
                    'tax' => (float) $item->tax_amount,
                ])->values()->all(),
                direction: 'output',
                customerId: $order->customer_id,
            );

            foreach (\App\Services\TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], $bookedTaxTotal) as $allocation) {
                if ($allocation['amount'] <= 0) {
                    continue;
                }
                $taxBaseTotal += $toBase((float) $allocation['amount']);
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: 0,
                    credit: $toBase((float) $allocation['amount']),
                    description: 'POS sales tax'.($allocation['tax_code'] ? " [{$allocation['tax_code']}]" : ''),
                    reference: $order->order_number,
                    taxCode: $allocation['tax_code'],
                    taxId: $allocation['tax_id'],
                    currencyCode: $foreign ? $baseCurrency : null,
                    originalAmount: $foreign ? round((float) $allocation['amount'], 2) : null,
                    exchangeRate: $foreign ? $rate : null,
                );
            }

            if ($foreign && $emitRevenue) {
                $revenueBase = round($toBase($totalPaid) - $taxBaseTotal, 2);
                $revenueOriginal = round($revenueBase / max($rate, 0.000001), 2);
            }
        }

        if ($emitRevenue) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: 0,
                credit: $revenueBase,
                description: 'POS sales revenue',
                reference: $order->order_number,
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? $revenueOriginal : null,
                exchangeRate: $foreign ? $rate : null,
            );
        }

        // COGS / Inventory
        // Issue #130: bundle lines cost the SUM of their components.
        $totalCost = 0.0;
        $bundleDecomposition = app(\App\Services\BundleDecompositionService::class);
        $batchConsumption = app(\App\Services\BatchSerialConsumptionService::class);
        $cogsWarehouseId = $order->terminal?->warehouse_id ? (int) $order->terminal->warehouse_id : null;

        foreach ($order->items as $item) {
            if ($item->product_variant_id && $item->variant) {
                $totalCost += (float) $item->variant->cost * (int) $item->quantity;

                continue;
            }

            if ($item->product) {
                $qty = (float) $item->quantity;
                // Issue #131: prefer real FEFO batch-layer cost when available.
                $totalCost += $batchConsumption->costForLine($item->product, $qty, $cogsWarehouseId)
                    ?? $bundleDecomposition->cost($item->product, $qty);
            }
        }

        // Entitlement safety / no-silent-no-op guard:
        // If this order contains stock-tracked items, GL must include COGS+inventory lines.
        if ($this->orderHasTrackedInventoryItems($order) && $totalCost <= 0) {
            $missingCostItems = $this->missingCostBasisItems($order);

            throw new \RuntimeException(
                sprintf(
                    '%s (COGS/inventory lines would be skipped). Affected items: %s',
                    self::MISSING_COST_BASIS_PREFIX,
                    implode(', ', $missingCostItems)
                )
            );
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
            documentType: 'pos_order',
            documentId: $order->id,
            postingDate: now()->toDateString(),
            description: "POS sale ({$paymentMethod})",
            lines: $lines,
            reference: $order->order_number,
            userId: TenantUserHelper::resolveTenantUserId(),
            legalEntityId: request()->attributes->get('legal_entity_id'),
            entityBookId: request()->attributes->get('entity_book_id'),
            taxDeterminationResult: $taxDetermination,
            // #37: the PermissionGate fails-closed on an unresolvable
            // required_permission for material postings. POS declares its own
            // coarse action permission here (module-level RBAC); the actor
            // creating a sale holds pos.create.
            requiredPermission: 'pos.create',
        ));

        if (! $result->success) {
            throw new \RuntimeException("POS posting failed: {$result->error}");
        }

        return $result->journalEntry;
    }

    private function orderHasTrackedInventoryItems(PosOrder $order): bool
    {
        return $order->items->contains(function ($item) {
            if ($item->product_variant_id && $item->variant) {
                return (bool) $item->variant->track_inventory;
            }

            if ($item->product_id && $item->product) {
                return (bool) $item->product->track_inventory;
            }

            return false;
        });
    }

    public static function isMissingCostBasisException(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), self::MISSING_COST_BASIS_PREFIX);
    }

    private function missingCostBasisItems(PosOrder $order): array
    {
        $bundleDecomposition = app(\App\Services\BundleDecompositionService::class);

        $items = $order->items
            ->filter(function ($item) use ($bundleDecomposition) {
                if ($item->product_variant_id && $item->variant) {
                    return (bool) $item->variant->track_inventory && (float) $item->variant->cost <= 0;
                }

                if ($item->product_id && $item->product) {
                    // Issue #130: a bundle's cost basis is the sum of its
                    // components, not the bundle's own (usually unset) cost.
                    return $bundleDecomposition->cost($item->product, (float) $item->quantity) <= 0;
                }

                return false;
            })
            ->map(function ($item) {
                if ($item->product_variant_id && $item->variant) {
                    return $item->variant->sku ?: $item->variant->name ?: 'variant#'.$item->product_variant_id;
                }

                if ($item->product_id && $item->product) {
                    return $item->product->sku ?: $item->product->name ?: 'product#'.$item->product_id;
                }

                return 'line#'.$item->id;
            })
            ->unique()
            ->values()
            ->all();

        return $items === [] ? ['unknown tracked item'] : $items;
    }

    /**
     * Reverse a previously posted POS order GL entry (used for POS returns).
     *
     * Finds the JournalEntryLink for the original order and calls
     * PostingEngine::reverse(). Must be called inside the same DB transaction
     * as stock restoration to guarantee atomicity.
     *
     * @param  PosOrder  $originalOrder  The paid order whose GL is being reversed
     * @param  PosOrder  $returnOrder  The return order (used for log context)
     * @param  string  $reason  Human-readable reversal reason
     * @return JournalEntry|null The reversal entry, or null if no GL existed
     */
    public function reverseOrder(PosOrder $originalOrder, PosOrder $returnOrder, string $reason = 'POS return', ?array $returnLines = null): ?JournalEntry
    {
        $link = JournalEntryLink::where('source_document_type', 'pos_order')
            ->where('source_document_id', $originalOrder->id)
            ->where('relation_type', 'post')
            ->first();

        if (! $link) {
            Log::warning('No journal entry found to reverse for POS return', [
                'original_order_id' => $originalOrder->id,
                'return_order_id' => $returnOrder->id,
            ]);

            return null;
        }

        // #268: a partial return must NOT fully reverse the original posting.
        // Partial returns route through the canonical proportional-reversal
        // machinery (signed tax trail rows + proportional GL). Full returns
        // (or legacy callers without line detail) keep the full reversal.
        if ($returnLines !== null) {
            $hasPriorReversal = JournalEntryLink::where('original_journal_entry_id', $link->journal_entry_id)
                ->where('relation_type', 'reversal')
                ->exists();

            if ($hasPriorReversal || ! $this->returnCoversWholeOrder($originalOrder, $returnLines)) {
                return $this->partialReverseOrder($originalOrder, (int) $link->journal_entry_id, $returnLines, $reason);
            }
        }

        $result = $this->postingEngine->reverse(new ReversalRequest(
            journalEntryId: $link->journal_entry_id,
            reversalDate: now()->toDateString(),
            reason: $reason,
            reasonCode: 'POS_RETURN',
            actorUserId: TenantUserHelper::resolveTenantUserId(),
            approvalUserId: null,
        ));

        if (! $result->success) {
            throw new \RuntimeException(
                "GL reversal failed for POS return of order {$originalOrder->order_number}: {$result->error}"
            );
        }

        Log::info('POS order posting reversed', [
            'original_order_id' => $originalOrder->id,
            'return_order_id' => $returnOrder->id,
            'reversal_entry_id' => $result->journalEntry?->id,
        ]);

        return $result->journalEntry;
    }

    /**
     * #268: true (per-line) partial reversal — GL lines proportional to the
     * returned items, tax trail rows signed per returned tax code, original
     * entry's remaining-reversible tracked by ReversalComponent (multiple
     * partial returns are safe).
     */
    private function partialReverseOrder(PosOrder $originalOrder, int $journalEntryId, array $returnLines, string $reason): ?JournalEntry
    {
        $original = JournalEntry::with('lines')->findOrFail($journalEntryId);
        $lineAmounts = $this->buildPartialLineAmounts($originalOrder, $original, $returnLines);

        if ($lineAmounts === []) {
            Log::warning('POS partial return produced no reversible line amounts', [
                'original_order_id' => $originalOrder->id,
            ]);

            return null;
        }

        $dto = new ReversalRequest(
            journalEntryId: $journalEntryId,
            reversalDate: now()->toDateString(),
            reason: $reason,
            reasonCode: 'POS_RETURN',
            actorUserId: TenantUserHelper::resolveTenantUserId(),
            tenantId: tenant()?->id,
        );

        $result = app(\App\Accounting\Services\ReversalCorrectionEngine::class)->partialReversal($dto, $lineAmounts);

        if (! $result->success) {
            throw new \RuntimeException(
                "Partial GL reversal failed for POS return of order {$originalOrder->order_number}: {$result->error}"
            );
        }

        Log::info('POS order partially reversed', [
            'original_order_id' => $originalOrder->id,
            'reversal_entry_id' => $result->journalEntry?->id,
        ]);

        return $result->journalEntry;
    }

    private function returnCoversWholeOrder(PosOrder $originalOrder, array $returnLines): bool
    {
        $requested = [];

        foreach ($returnLines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $requested[$itemId] = ($requested[$itemId] ?? 0) + (float) ($line['quantity'] ?? 0);
        }

        foreach ($originalOrder->items as $item) {
            if (($requested[$item->id] ?? 0.0) < (float) $item->quantity) {
                return false;
            }
        }

        return $originalOrder->items->isNotEmpty();
    }

    /**
     * Amounts for exactly the returned items, mapped onto the original JE
     * lines: cash = returned net + returned tax; revenue = returned net;
     * tax per returned tax code (from the persisted determination); cost
     * lines (COGS/inventory) at the returned-quantity ratio.
     *
     * @return array<int, array{line_id: int, amount: float}>
     */
    private function buildPartialLineAmounts(PosOrder $originalOrder, JournalEntry $original, array $returnLines): array
    {
        $items = $originalOrder->items()->get()->keyBy('id');
        $determination = app(\App\Services\TaxDeterminationService::class)
            ->latestDeterminationLines('pos_order', (int) $originalOrder->id);

        $totalQty = (float) $originalOrder->items->sum('quantity');
        $returnedQty = 0.0;
        $returnedNet = 0.0;
        $taxByCode = [];

        foreach ($returnLines as $line) {
            $item = $items[(int) ($line['item_id'] ?? 0)] ?? null;
            $qty = (float) ($line['quantity'] ?? 0);

            if (! $item || $qty <= 0 || (float) $item->quantity <= 0) {
                continue;
            }

            $ratio = min($qty / (float) $item->quantity, 1.0);
            $gross = round((float) $item->line_total * $ratio, 2); // line_total is tax-inclusive for POS items
            $tax = round((float) $item->tax_amount * $ratio, 2);

            $returnedQty += $qty;
            $returnedNet = round($returnedNet + ($gross - $tax), 2);

            if ($tax > 0) {
                $code = (string) ($determination[$item->id]['tax_code'] ?? '');
                $taxByCode[$code] = round(($taxByCode[$code] ?? 0) + $tax, 2);
            }
        }

        if ($returnedQty <= 0) {
            return [];
        }

        $qtyRatio = $totalQty > 0 ? min($returnedQty / $totalQty, 1.0) : 0.0;

        $accountId = fn (string $key): int => (int) (\App\Models\AccountMapping::where('mapping_key', $key)->value('account_id') ?: 0);
        $mappingIds = [
            'cash' => $accountId('cash'),
            'sales_revenue' => $accountId('sales_revenue'),
            'sales_tax_payable' => $accountId('sales_tax_payable'),
            'cogs' => $accountId('cogs'),
            'inventory' => $accountId('inventory'),
        ];

        $revenueLines = [];
        $taxLines = [];
        $cashLines = [];
        $costLines = [];

        foreach ($original->lines as $line) {
            if ($line->tax_code !== null || ($mappingIds['sales_tax_payable'] && (int) $line->account_id === $mappingIds['sales_tax_payable'])) {
                $taxLines[] = $line;
            } elseif ($mappingIds['sales_revenue'] && (int) $line->account_id === $mappingIds['sales_revenue']) {
                $revenueLines[] = $line;
            } elseif ($mappingIds['cash'] && (int) $line->account_id === $mappingIds['cash']) {
                $cashLines[] = $line;
            } elseif (
                ($mappingIds['cogs'] && (int) $line->account_id === $mappingIds['cogs'])
                || ($mappingIds['inventory'] && (int) $line->account_id === $mappingIds['inventory'])
            ) {
                $costLines[] = $line;
            }
        }

        $amounts = [];

        // Tax per returned code, allocated across the original tax lines.
        foreach ($taxByCode as $code => $taxAmount) {
            $matching = $code === ''
                ? $taxLines
                : array_values(array_filter($taxLines, fn ($l) => (string) $l->tax_code === $code));

            if ($matching === []) {
                $matching = $taxLines;
            }

            $amounts = array_merge($amounts, $this->allocateAcross($matching, $taxAmount));
        }

        $amounts = array_merge($amounts, $this->allocateAcross($revenueLines, $returnedNet));

        $taxTotal = round(array_sum($taxByCode), 2);
        $amounts = array_merge($amounts, $this->allocateAcross($cashLines, round($returnedNet + $taxTotal, 2)));

        // COGS / inventory revert at the returned-quantity ratio (both sides
        // share the ratio, so the pair stays balanced).
        foreach ($costLines as $line) {
            $amount = round((float) max($line->debit, $line->credit) * $qtyRatio, 2);

            if ($amount > 0) {
                $amounts[] = ['line_id' => (int) $line->id, 'amount' => $amount];
            }
        }

        // Rounding residual guard: keep the mirrored entry balanced by nudging
        // the cash line (or the largest line) by the difference.
        return $this->balancePartialAmounts($amounts, $cashLines, $original->lines->keyBy('id'));
    }

    /**
     * @param  array<int, \App\Models\JournalEntryLine>  $lines
     * @return array<int, array{line_id: int, amount: float}>
     */
    private function allocateAcross(array $lines, float $total): array
    {
        if ($lines === [] || $total <= 0) {
            return [];
        }

        $sum = array_sum(array_map(fn ($l) => (float) max($l->debit, $l->credit), $lines));
        $amounts = [];
        $running = 0.0;
        $last = count($lines) - 1;

        foreach ($lines as $i => $line) {
            $lineTotal = (float) max($line->debit, $line->credit);
            $amount = $i === $last
                ? round($total - $running, 2)
                : ($sum > 0 ? round($total * ($lineTotal / $sum), 2) : 0.0);

            if ($amount > 0) {
                $amounts[] = ['line_id' => (int) $line->id, 'amount' => $amount];
                $running = round($running + $amount, 2);
            }
        }

        return $amounts;
    }

    /**
     * @param  array<int, array{line_id: int, amount: float}>  $amounts
     * @param  array<int, \App\Models\JournalEntryLine>  $cashLines
     */
    private function balancePartialAmounts(array $amounts, array $cashLines, \Illuminate\Support\Collection $linesById): array
    {
        $debits = 0.0;
        $credits = 0.0;

        foreach ($amounts as $entry) {
            $line = $linesById[$entry['line_id']] ?? null;

            if (! $line) {
                continue;
            }

            if ((float) $line->debit > 0) {
                $debits = round($debits + $entry['amount'], 2);
            } else {
                $credits = round($credits + $entry['amount'], 2);
            }
        }

        $diff = round($debits - $credits, 2);

        if (abs($diff) < 0.01 || $amounts === []) {
            return $amounts;
        }

        $targetLineId = $cashLines !== []
            ? (int) $cashLines[0]->id
            : (int) collect($amounts)->sortByDesc('amount')->first()['line_id'];

        foreach ($amounts as $i => $entry) {
            if ($entry['line_id'] === $targetLineId) {
                $amounts[$i]['amount'] = round($entry['amount'] - $diff, 2);

                if ($amounts[$i]['amount'] <= 0) {
                    unset($amounts[$i]);
                }

                break;
            }
        }

        return array_values($amounts);
    }

    /**
     * Post GL entry for refund payment (cash out)
     *
     * This creates a journal entry for the refund payment itself,
     * separate from the sales reversal. This ensures cash drawer
     * reconciliation works correctly.
     *
     * @param  PosOrder  $order  The order being refunded
     * @param  string  $refundMethod  Payment method (cash, card, store_credit)
     * @param  float  $refundAmount  Amount being refunded
     * @param  string  $description  Description for the entry
     * @return JournalEntry The refund payment journal entry
     */
    public function postRefundPayment(
        PosOrder $order,
        string $refundMethod,
        float $refundAmount,
        string $description
    ): JournalEntry {
        $lines = [];

        // CR Cash (money going out)
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'cash',
            accountId: null,
            debit: 0,
            credit: $refundAmount,
            description: 'Refund payment',
            reference: $order->order_number,
        );

        // DR Refunds Payable or Sales Returns
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'refunds_payable',
            accountId: null,
            debit: $refundAmount,
            credit: 0,
            description: 'Refund liability',
            reference: $order->order_number,
        );

        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'pos_refund_payment',
            documentId: $order->id,
            postingDate: now()->toDateString(),
            description: $description,
            lines: $lines,
            reference: "REFUND-{$order->order_number}",
            userId: TenantUserHelper::resolveTenantUserId(),
            legalEntityId: request()->attributes->get('legal_entity_id'),
            entityBookId: request()->attributes->get('entity_book_id'),
        ));

        if (! $result->success) {
            throw new \RuntimeException("Refund payment posting failed: {$result->error}");
        }

        Log::info('POS refund payment posted', [
            'order_id' => $order->id,
            'refund_amount' => $refundAmount,
            'refund_method' => $refundMethod,
            'journal_entry_id' => $result->journalEntry->id,
        ]);

        return $result->journalEntry;
    }
}
