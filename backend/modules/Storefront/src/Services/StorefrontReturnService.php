<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontReturn;

/**
 * P4 returns (FR-ORD-06, A-22): request → approve/reject → receive → refund/
 * close, tied to the original order. The receive step is where stock comes
 * back (refunds are deliberately money-only — this is the restock layer
 * disclosed as deferred in slice 8), and the money side reuses the canonical
 * StorefrontRefundService (no second refund mechanism: intent, one cash-
 * shaped reversal, invoice/customer bookkeeping, machine state, mail).
 *
 * Every customer-visible transition sends the FR-MSG sequence through the
 * Communication engine (return.requested/approved/rejected/received/
 * refunded), each individually merchant-toggleable.
 */
final class StorefrontReturnService
{
    public function __construct(
        private readonly StorefrontRefundService $refunds,
        private readonly StorefrontPostingService $posting,
        private readonly InventoryService $inventory,
        private readonly StorefrontNotificationService $notifications,
    ) {}

    /**
     * @param  array<int,array{line_id:int,quantity:float}>  $lines
     */
    public function request(
        StorefrontOrder $order,
        array $lines,
        string $reason,
        ?StorefrontAccount $account = null,
    ): StorefrontReturn {
        if ($lines === []) {
            throw new CheckoutException('lines_required', 'Select at least one line to return.', 422);
        }
        if (! in_array((string) $order->payment_status, [PaymentStatus::PAID->value, PaymentStatus::PARTIALLY_REFUNDED->value], true)
            || ! in_array((string) $order->status, [OrderStatus::PAID->value, OrderStatus::PROCESSING->value, OrderStatus::SHIPPED->value, OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true)) {
            throw new CheckoutException('not_returnable', 'This order is not eligible for returns.', 422);
        }

        return DB::transaction(function () use ($order, $lines, $reason, $account): StorefrontReturn {
            /** @var StorefrontOrder $locked */
            $locked = StorefrontOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $locked->items()->get()->keyBy('id');

            $clean = [];
            $proposed = 0.0;
            foreach ($lines as $entry) {
                $item = $items->get((int) ($entry['line_id'] ?? 0));
                if ($item === null) {
                    throw new CheckoutException('line_not_found', 'Order line not found.', 422);
                }
                $qty = round((float) ($entry['quantity'] ?? 0), 3);
                $returnable = round((float) $item->quantity - (float) $item->returned_quantity, 3);
                if ($qty <= 0 || $qty > $returnable + 0.0001) {
                    throw new CheckoutException('quantity_exceeds', "At most {$returnable} of \"{$item->name}\" can be returned.", 422);
                }
                $perUnit = (float) $item->quantity > 0 ? (float) $item->line_total / (float) $item->quantity : 0.0;
                $proposed += round($perUnit * $qty, 2);
                $clean[] = ['line_id' => (int) $item->id, 'quantity' => $qty, 'name' => $item->name];
            }

            $ret = StorefrontReturn::create([
                'order_id' => (int) $locked->id,
                'account_id' => $account?->id,
                'status' => 'requested',
                'reason' => mb_substr($reason, 0, 500),
                'refund_amount' => round($proposed, 2),
                'line_items' => $clean,
            ]);

            $this->notify($ret, 'requested');

            return $ret;
        });
    }

    public function approve(StorefrontReturn $ret, ?float $refundAmount, ?string $note, ?string $reviewer = null): StorefrontReturn
    {
        $this->assertStatus($ret, 'requested', 'approve');

        return DB::transaction(function () use ($ret, $refundAmount, $note, $reviewer): StorefrontReturn {
            $ret->update([
                'status' => 'approved',
                'refund_amount' => $refundAmount !== null ? round($refundAmount, 2) : $ret->refund_amount,
                'notes' => $note !== null ? mb_substr($note, 0, 2000) : $ret->notes,
                'reviewed_by' => $reviewer,
                'reviewed_at' => now(),
            ]);

            $this->notify($ret->fresh(), 'approved');

            return $ret->fresh();
        });
    }

    public function reject(StorefrontReturn $ret, ?string $note, ?string $reviewer = null): StorefrontReturn
    {
        $this->assertStatus($ret, 'requested', 'reject');

        return DB::transaction(function () use ($ret, $note, $reviewer): StorefrontReturn {
            $ret->update([
                'status' => 'rejected',
                'notes' => $note !== null ? mb_substr($note, 0, 2000) : $ret->notes,
                'reviewed_by' => $reviewer,
                'reviewed_at' => now(),
            ]);

            $this->notify($ret->fresh(), 'rejected');

            return $ret->fresh();
        });
    }

    /**
     * Receive the physical goods: restock tracked lines through the canonical
     * InventoryService (idempotent movement key per return line — double
     * receiving cannot exist) and reverse the COGS side of the sale.
     */
    public function receive(StorefrontReturn $ret, ?string $reviewer = null): StorefrontReturn
    {
        $this->assertStatus($ret, 'approved', 'receive');

        return DB::transaction(function () use ($ret, $reviewer): StorefrontReturn {
            /** @var StorefrontOrder $order */
            $order = StorefrontOrder::query()->whereKey($ret->order_id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->get()->keyBy('id');

            $restockCost = 0.0;
            $restockLines = [];

            foreach ((array) $ret->line_items as $line) {
                $item = $items->get((int) $line['line_id']);
                if ($item === null) {
                    continue;
                }
                $qty = round((float) $line['quantity'], 3);
                $item->update(['returned_quantity' => round((float) $item->returned_quantity + $qty, 3)]);

                $product = $item->product_id ? Product::query()->find($item->product_id) : null;
                if ($product !== null && $product->track_inventory) {
                    $warehouseId = (int) ($order->fulfillment_warehouse_id ?? 0);
                    if ($warehouseId <= 0) {
                        throw new CheckoutException('restock_unresolved', 'The order has no fulfillment warehouse to receive stock back into.', 422);
                    }
                    $this->inventory->adjustStock(
                        productId: (int) $product->id,
                        warehouseId: $warehouseId,
                        quantity: $qty,
                        type: 'return',
                        reference: $order->order_number,
                        referenceType: 'storefront_return',
                        referenceId: (int) $ret->id,
                        notes: 'Customer return received',
                        idempotencyKey: "storefront-return-{$ret->id}-line-{$item->id}",
                    );
                    $cost = round((float) $product->cost * $qty, 2);
                    if ($cost > 0) {
                        $restockCost += $cost;
                        $restockLines[] = ['name' => $product->name, 'quantity' => $qty, 'cost' => $cost];
                    }
                }
            }

            if ($restockCost > 0) {
                $this->posting->postReturnRestock($order, (int) $ret->id, round($restockCost, 2));
            }

            $ret->update(['status' => 'received', 'received_at' => now(), 'reviewed_by' => $reviewer ?? $ret->reviewed_by]);

            $this->notify($ret->fresh(), 'received');

            return $ret->fresh();
        });
    }

    /**
     * Refund a received return through the canonical refund chain (intent,
     // one reversal, invoice/customer bookkeeping, order/payment machine,
     // lifecycle mail) with a caller-scoped Idempotency key so replaying the
     // return can never double-refund.
     */
    public function refund(StorefrontReturn $ret, ?int $actorUserId = null, ?string $reviewer = null): StorefrontReturn
    {
        $this->assertStatus($ret, 'received', 'refund');

        $amount = round((float) $ret->refund_amount, 2);
        if ($amount <= 0) {
            throw new CheckoutException('nothing_to_refund', 'This return carries no refund amount (close it instead).', 422);
        }

        $order = StorefrontOrder::query()->findOrFail($ret->order_id);
        $transaction = $this->refunds->settledTransactionFor($order);

        $this->refunds->refund(
            $transaction,
            $amount,
            "Return #{$ret->id} ({$ret->reason})",
            viaGateway: false,
            actorUserId: $actorUserId,
            idempotencyKey: 'sf-return-'.$ret->id,
        );

        $ret->update(['status' => 'refunded', 'refunded_at' => now(), 'reviewed_by' => $reviewer ?? $ret->reviewed_by]);

        $this->notify($ret->fresh(), 'refunded');

        return $ret->fresh();
    }

    public function close(StorefrontReturn $ret, ?string $note, ?string $reviewer = null): StorefrontReturn
    {
        if (! in_array($ret->status, ['approved', 'received'], true)) {
            throw new CheckoutException('invalid_return_transition', "A {$ret->status} return cannot be closed.", 409);
        }

        $ret->update([
            'status' => 'closed',
            'notes' => $note !== null ? ($ret->notes.' | '.$note) : $ret->notes,
            'reviewed_by' => $reviewer ?? $ret->reviewed_by,
        ]);

        return $ret->fresh();
    }

    private function assertStatus(StorefrontReturn $ret, string $expected, string $action): void
    {
        if ($ret->status !== $expected) {
            throw new CheckoutException(
                'invalid_return_transition',
                "Cannot {$action} a return in status '{$ret->status}'.",
                409,
            );
        }
    }

    private function notify(StorefrontReturn $ret, string $event): void
    {
        try {
            $this->notifications->returnEvent($ret, $event);
        } catch (\Throwable) {
            // messaging is failure-contained by design
        }
    }
}
