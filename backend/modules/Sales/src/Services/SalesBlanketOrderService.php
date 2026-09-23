<?php

namespace Modules\Sales\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesBlanketOrder;
use Modules\Sales\Models\SalesBlanketOrderLine;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #152: blanket order / call-off agreements.
 *
 * - create(): header + committed lines (quantity + locked price + validity).
 * - release(): generates a normal SalesOrder from the agreement through the
 *   existing SalesOrderService::createOrder() path, decrements the released
 *   quantity and enforces the agreed price unless an explicit override is
 *   requested.
 * - summary(): committed vs released vs remaining visibility per agreement.
 */
class SalesBlanketOrderService
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
    ) {}

    public function create(array $data): SalesBlanketOrder
    {
        return DB::transaction(function () use ($data) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['blanket_number'] = $data['blanket_number'] ?? SalesBlanketOrder::generateNumber();
            $data['status'] = $data['status'] ?? 'active';

            $blanket = SalesBlanketOrder::create($data);

            foreach ($lines as $line) {
                SalesBlanketOrderLine::create([
                    'blanket_order_id' => $blanket->id,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'] ?? null,
                    'committed_qty' => round((float) $line['committed_qty'], 4),
                    'released_qty' => 0,
                    'unit_price' => round((float) $line['unit_price'], 2),
                    'unit' => $line['unit'] ?? null,
                ]);
            }

            return $blanket->load('lines.product');
        });
    }

    /**
     * Create a release sales order from the agreement.
     *
     * @param  array<int, array{blanket_line_id: int, quantity: float, unit_price?: float}>  $releaseLines
     */
    public function release(
        SalesBlanketOrder $blanket,
        array $releaseLines,
        bool $allowPriceOverride = false,
        ?string $notes = null,
    ): SalesOrder {
        if (! $blanket->isActiveOn(now())) {
            throw new \RuntimeException('This blanket order is not active for the current date.');
        }

        return DB::transaction(function () use ($blanket, $releaseLines, $allowPriceOverride, $notes) {
            $blanket->loadMissing('lines');

            $orderLines = [];

            foreach ($releaseLines as $release) {
                $line = $blanket->lines->firstWhere('id', $release['blanket_line_id']);

                if (! $line) {
                    throw new \RuntimeException("Blanket line {$release['blanket_line_id']} does not belong to this agreement.");
                }

                $quantity = round((float) $release['quantity'], 4);

                if ($quantity <= 0) {
                    throw new \RuntimeException('Release quantities must be greater than zero.');
                }

                if ($quantity > $line->remainingQty() + 0.0001) {
                    throw new \RuntimeException(sprintf(
                        'Release quantity %.4f exceeds the remaining committed quantity %.4f for product %d.',
                        $quantity,
                        $line->remainingQty(),
                        $line->product_id,
                    ));
                }

                // Price consistency: releases must use the agreed price unless
                // the caller explicitly overrides it.
                if (isset($release['unit_price']) && ! $allowPriceOverride
                    && abs((float) $release['unit_price'] - (float) $line->unit_price) > 0.001) {
                    throw new \RuntimeException(sprintf(
                        'Release price %.2f differs from the agreed blanket price %.2f. '
                        .'Pass allow_price_override to proceed deliberately.',
                        (float) $release['unit_price'],
                        (float) $line->unit_price,
                    ));
                }

                $line->update(['released_qty' => round((float) $line->released_qty + $quantity, 4)]);

                $orderLines[] = [
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $quantity,
                    'unit_price' => $allowPriceOverride && isset($release['unit_price'])
                        ? (float) $release['unit_price']
                        : (float) $line->unit_price,
                    'unit' => $line->unit,
                ];
            }

            $order = $this->salesOrderService->createOrder([
                'customer_id' => $blanket->customer_id,
                'order_date' => now()->toDateString(),
                'currency' => $blanket->currency,
                'notes' => trim("Release from blanket order {$blanket->blanket_number}. ".($notes ?? '')),
                'lines' => $orderLines,
            ]);

            return $order->fresh(['lines.product']);
        });
    }

    /**
     * Committed vs released vs remaining per line, plus totals.
     */
    public function summary(SalesBlanketOrder $blanket): array
    {
        $blanket->loadMissing('lines.product');

        $lines = $blanket->lines->map(fn (SalesBlanketOrderLine $line) => [
            'blanket_line_id' => $line->id,
            'product_id' => $line->product_id,
            'product_name' => $line->product?->name,
            'sku' => $line->product?->sku,
            'committed_qty' => round((float) $line->committed_qty, 4),
            'released_qty' => round((float) $line->released_qty, 4),
            'remaining_qty' => $line->remainingQty(),
            'unit_price' => round((float) $line->unit_price, 2),
            'committed_value' => round((float) $line->committed_qty * (float) $line->unit_price, 2),
            'released_value' => round((float) $line->released_qty * (float) $line->unit_price, 2),
            'remaining_value' => round($line->remainingQty() * (float) $line->unit_price, 2),
        ])->values()->all();

        return [
            'blanket' => [
                'id' => $blanket->id,
                'blanket_number' => $blanket->blanket_number,
                'customer_id' => $blanket->customer_id,
                'customer_name' => Customer::whereKey($blanket->customer_id)->value('name'),
                'status' => $blanket->status,
                'valid_from' => $blanket->valid_from?->toDateString(),
                'valid_to' => $blanket->valid_to?->toDateString(),
                'currency' => $blanket->currency,
            ],
            'lines' => $lines,
            'totals' => [
                'committed_value' => round(array_sum(array_column($lines, 'committed_value')), 2),
                'released_value' => round(array_sum(array_column($lines, 'released_value')), 2),
                'remaining_value' => round(array_sum(array_column($lines, 'remaining_value')), 2),
            ],
        ];
    }
}
