<?php

namespace Modules\Sales\Services;

use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Services\QuotationMarginService;
use App\Services\SalesApprovalGate;
use Modules\Inventory\Services\InventoryService;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #412 (SALES-DIFF DX2): the order health panel shown before
 * Confirm/Send. A read-only dry run that composes the checks the order will
 * meet on confirmation — mirroring the invoice pre-flight (D7,
 * InvoicePreflightService) — and reports pass / warn / fail per check:
 *
 *  - stock per line: InventoryService::stockAvailability() (#465/#686, the
 *    order's own reservation counts as available to it) plus incoming
 *    supply from open purchase orders / inbound transfers
 *    (InventoryService::incomingStock(), #687) for the backorder date, and
 *    the #408 commitment date at risk. Confirm backorders a short line
 *    instead of refusing it (#379), so stock never fails the panel;
 *  - credit: SalesOrderService::confirmCreditCheck() — the exact verdict
 *    confirm gates on (base currency, committed open orders #240/#383/#384).
 *    Confirm refuses an over-limit draft, so that is a fail;
 *  - approval: SalesApprovalGate — whether confirm will raise an approval
 *    request and why. Confirm raises the request rather than failing, so
 *    this warns;
 *  - margin per line and total via QuotationMarginService (#338 cost
 *    basis), only for roles holding its cost capability (pricelists.cost).
 *
 * Nothing here writes: no reservation, no approval request, no lock.
 * Confirmation keeps its own hard gates; the panel is advisory.
 */
class SalesOrderHealthService
{
    public function __construct(
        private SalesOrderService $orderService,
        private SalesApprovalGate $approvalGate,
        private InventoryService $inventory,
        private QuotationMarginService $margins,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(SalesOrder $order): array
    {
        $order->loadMissing(['lines.product', 'customer']);

        $productLines = $order->lines
            ->filter(fn ($line) => ! in_array($line->line_type ?? 'product', ['note', 'section'], true))
            ->values();

        $canSeeCost = $this->margins->actorMaySeeCost();
        $margin = $canSeeCost ? $this->margins->calculate($order) : null;

        $lines = $this->stockLines($order, $productLines, $margin);
        $credit = $this->credit($order);
        $approval = $this->approval($order);

        $checks = array_values(array_filter([
            $productLines->isNotEmpty()
                ? $this->pass('lines', 'Has line items', $productLines->count().' line(s) on the order.')
                : $this->fail('lines', 'Has line items', 'The order has no line items.'),
            $this->stockCheck($order, $lines),
            $this->creditCheck($order, $credit),
            $this->approvalCheck($approval),
            $margin !== null ? $this->marginCheck($margin['total']) : null,
        ]));

        $passed = count(array_filter($checks, fn ($check) => $check['status'] === 'pass'));
        $failed = count(array_filter($checks, fn ($check) => $check['status'] === 'fail'));

        return [
            'sales_order_id' => (int) $order->id,
            'status' => $order->status,
            'ready' => $failed === 0,
            'passed' => $passed,
            'total' => count($checks),
            'checks' => $checks,
            'lines' => $lines,
            'credit' => $credit,
            'approval' => $approval,
            'can_see_cost' => $canSeeCost,
            'margin' => $margin === null ? null : [
                'total' => $margin['total'],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Modules\Sales\Models\SalesOrderLine>  $productLines
     * @param  array<string, mixed>|null  $margin
     * @return array<int, array<string, mixed>>
     */
    private function stockLines(SalesOrder $order, $productLines, ?array $margin): array
    {
        $warehouseId = $order->warehouse_id ? (int) $order->warehouse_id : null;
        $commitment = $order->commitment_date?->toDateString();
        $rows = [];

        foreach ($productLines as $line) {
            $product = $line->product;
            $tracked = $product !== null && (bool) ($product->track_inventory ?? false);
            $open = max(0.0, (float) $line->quantity - (float) ($line->quantity_cancelled ?? 0) - (float) ($line->quantity_delivered ?? 0));

            $row = [
                'line_id' => (int) $line->id,
                'product_id' => $line->product_id !== null ? (int) $line->product_id : null,
                'product_name' => $product?->name,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'open_quantity' => $open,
                'tracked' => $tracked,
                'warehouse_id' => $warehouseId,
                'on_hand' => null,
                'reserved' => null,
                'available' => null,
                'shortage' => 0.0,
                'incoming_quantity' => 0.0,
                'incoming' => [],
                'backorder_date' => null,
                'promise_at_risk' => false,
                'status' => 'pass',
                'detail' => $tracked ? 'Available.' : 'Not stock-tracked.',
            ];

            if ($tracked && $warehouseId === null) {
                $row['status'] = 'warn';
                $row['detail'] = 'No warehouse on the order — stock cannot be checked.';
            } elseif ($tracked) {
                $row = $this->stockFor($order, $row, (int) $line->product_id, $warehouseId, $open, $commitment);
            }

            if ($margin !== null && isset($margin['lines'][$line->id])) {
                $figures = $margin['lines'][$line->id];
                $row['revenue'] = $figures['revenue'];
                $row['cost'] = $figures['cost'];
                $row['margin'] = $figures['margin'];
                $row['margin_percent'] = $figures['margin_percent'];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function stockFor(SalesOrder $order, array $row, int $productId, int $warehouseId, float $open, ?string $commitment): array
    {
        $availability = $this->inventory->stockAvailability($productId, $warehouseId, 'SalesOrder', (int) $order->id);
        $incoming = $this->inventory->incomingStock($productId, $warehouseId);

        $row['on_hand'] = $availability['on_hand'];
        $row['reserved'] = $availability['reserved'];
        $row['available'] = $availability['available'];
        $row['incoming'] = $incoming;
        $row['incoming_quantity'] = (float) array_sum(array_column($incoming, 'quantity'));

        $shortage = $open - (float) $availability['available'];

        if ($shortage <= 0.0000001) {
            return $row;
        }

        $row['shortage'] = round($shortage, 4);
        $row['status'] = 'warn';

        // Earliest dated supply first; the backorder date is the expected
        // date of the receipt that covers the shortage cumulatively. Supply
        // without a date (or not enough supply) gives no date — never a guess.
        usort($incoming, fn ($a, $b) => [$a['expected_date'] === null, $a['expected_date']] <=> [$b['expected_date'] === null, $b['expected_date']]);

        $cumulative = 0.0;
        $coveredBy = null;
        foreach ($incoming as $supply) {
            $cumulative += (float) $supply['quantity'];
            if ($cumulative + 0.0000001 >= $shortage) {
                $coveredBy = $supply;
                break;
            }
        }

        $row['backorder_date'] = $coveredBy['expected_date'] ?? null;

        $shortText = 'Short by '.$this->quantity($shortage).' ('.$this->quantity((float) $availability['available']).' available)';

        if ($coveredBy === null) {
            $row['detail'] = $shortText.' — no open purchase order or inbound transfer covers it; it will be backordered.';
        } elseif ($row['backorder_date'] === null) {
            $row['detail'] = $shortText.' — backorder; incoming '.($coveredBy['reference'] ?? 'supply').' has no expected date.';
        } else {
            $row['detail'] = $shortText.' — backorder until '.$row['backorder_date'].' ('.($coveredBy['reference'] ?? 'incoming supply').').';
        }

        // Issue #408: the promise date cannot be met when the stock arrives
        // after it, or when no dated supply covers the shortage at all.
        if ($commitment !== null && ($row['backorder_date'] === null || $row['backorder_date'] > $commitment)) {
            $row['promise_at_risk'] = true;
            $row['detail'] .= ' Promise date '.$commitment.' is at risk.';
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function stockCheck(SalesOrder $order, array $lines): array
    {
        $tracked = array_filter($lines, fn ($line) => $line['tracked']);

        if ($tracked === []) {
            return $this->pass('stock', 'Stock availability', 'No stock-tracked products on this order.');
        }

        $short = array_filter($tracked, fn ($line) => $line['status'] !== 'pass');

        if ($short === []) {
            return $this->pass('stock', 'Stock availability', 'All tracked lines are available.');
        }

        $atRisk = count(array_filter($short, fn ($line) => $line['promise_at_risk']));
        $names = implode(', ', array_map(fn ($line) => $line['product_name'] ?? $line['description'] ?? ('line '.$line['line_id']), $short));

        return $this->warn(
            'stock',
            'Stock availability',
            sprintf('%d line(s) short: %s.', count($short), $names)
                .($order->warehouse_id ? ' Confirming backorders them.' : '')
                .($atRisk > 0 ? " Promise date at risk on {$atRisk} line(s)." : ''),
        );
    }

    /**
     * The verdict confirmation gates on (confirmCreditCheck), plus the
     * exposure it was judged on. Null without a customer.
     *
     * @return array<string, mixed>|null
     */
    private function credit(SalesOrder $order): ?array
    {
        if (! $order->customer_id) {
            return null;
        }

        $customer = Customer::withTrashed()->whereKey($order->customer_id)->first();

        if (! $customer) {
            return null;
        }

        try {
            $result = $this->orderService->confirmCreditCheck($order, $customer);
        } catch (\Throwable $e) {
            // No exchange rate to put the order in base currency: confirm
            // would fail the same way, so report it rather than guess.
            return ['error' => $e->getMessage()];
        }

        return $result->toArray() + [
            'exposure' => $result->availableCredit === null
                ? null
                : round($result->creditLimit - $result->availableCredit, 2),
            'base_currency_code' => $order->base_currency_code,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $credit
     */
    private function creditCheck(SalesOrder $order, ?array $credit): array
    {
        if ($credit === null) {
            return $this->warn('credit', 'Credit limit', 'No customer on this order.');
        }

        if (isset($credit['error'])) {
            return $this->fail('credit', 'Credit limit', 'Credit cannot be checked: '.$credit['error']);
        }

        if ($credit['approved']) {
            return $this->pass('credit', 'Credit limit', $credit['available_credit'] === null
                ? 'No credit limit set for this customer.'
                : sprintf(
                    'Exposure %s + this order %s of %s limit.',
                    number_format((float) $credit['exposure'], 2),
                    number_format((float) $credit['document_total'], 2),
                    number_format((float) $credit['credit_limit'], 2),
                ));
        }

        // Confirm refuses an over-limit draft (CreditLimitExceededException);
        // once confirmed the gate has already been passed, so it only warns.
        return $order->isDraft()
            ? $this->fail('credit', 'Credit limit', $credit['reason'].' — confirming will be refused.')
            : $this->warn('credit', 'Credit limit', $credit['reason'].'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function approval(SalesOrder $order): array
    {
        $type = SalesApprovalGate::REQUEST_TYPE_SALES_ORDER;
        $threshold = $this->approvalGate->threshold($order);
        $baseTotal = $this->approvalGate->baseCurrencyAmount($order, (float) $order->total);
        $required = $this->approvalGate->requiresApproval($order::class, (float) $order->total, $order);

        $state = 'not_required';
        $requestId = null;

        if ($required) {
            if ($this->approvalGate->hasApprovedRequest($order, $type)) {
                $state = 'approved';
            } else {
                $pending = ApprovalRequest::query()
                    ->where('subject_type', $order::class)
                    ->where('subject_id', $order->id)
                    ->where('request_type', $type)
                    ->where('status', 'pending')
                    ->latest('id')
                    ->first();

                if ($pending !== null && $this->approvalGate->requestMatchesDocument($pending, $order)) {
                    $state = 'pending';
                    $requestId = (int) $pending->id;
                } else {
                    $state = 'not_requested';
                }
            }
        }

        $reason = null;
        if ($required) {
            if ($baseTotal === null) {
                $reason = 'The order has no usable exchange rate, so approval is required as a safeguard.';
            } elseif ($threshold !== null && $threshold > 0.0 && $baseTotal >= $threshold) {
                $reason = sprintf(
                    'Order total %s %s is at or above the approval threshold %s.',
                    number_format($baseTotal, 2),
                    (string) ($order->base_currency_code ?? ''),
                    number_format($threshold, 2),
                );
            } else {
                $reason = 'An approval rule applies to this order.';
            }
        }

        return [
            'required' => $required,
            'state' => $state,
            'reason' => $reason,
            'threshold' => $threshold,
            'base_total' => $baseTotal,
            'approval_request_id' => $requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $approval
     */
    private function approvalCheck(array $approval): array
    {
        return match ($approval['state']) {
            'not_required' => $this->pass('approval', 'Approval', 'No approval required.'),
            'approved' => $this->pass('approval', 'Approval', 'Approved.'),
            'pending' => $this->warn('approval', 'Approval', 'Awaiting approval. '.$approval['reason']),
            default => $this->warn('approval', 'Approval', 'Confirming will send the order for approval. '.$approval['reason']),
        };
    }

    /**
     * @param  array<string, mixed>  $total
     */
    private function marginCheck(array $total): array
    {
        $percent = $total['margin_percent'];
        $text = sprintf(
            'Margin %s%s on revenue %s.',
            number_format((float) $total['margin'], 2),
            $percent === null ? '' : ' ('.number_format((float) $percent, 1).'%)',
            number_format((float) $total['revenue'], 2),
        );

        return (float) $total['margin'] < 0.0
            ? $this->warn('margin', 'Margin', $text.' The order sells below cost.')
            : $this->pass('margin', 'Margin', $text);
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    private function pass(string $key, string $label, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => 'pass'];
    }

    private function warn(string $key, string $label, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => 'warn'];
    }

    private function fail(string $key, string $label, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => 'fail'];
    }
}
