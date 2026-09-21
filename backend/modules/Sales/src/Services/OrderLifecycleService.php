<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;

class OrderLifecycleService
{
    /**
     * Issue #141 (BLOCKER): the transition table must agree with the business
     * contracts that SalesOrderService's own method guards already implement.
     * Previously only 'fulfilled' could reach 'invoiced' and 'cancelled' had no
     * incoming edge from 'fulfilled', which silently broke:
     *   - createInvoice() from confirmed/allocated (deposit, invoice-before-
     *     fulfilment) and repeated invoicing (multi/partial-invoice);
     *   - cancelOrder() of a fulfilled-but-unbilled order.
     * Additionally 'cancelled' -> 'draft' supports the reset-to-draft path
     * (controller guard: only cancelled orders with no linked invoices).
     *
     * Audited against every guard in SalesOrderService + SalesOrderController:
     *   confirmOrder  -> isDraft()       -> confirmed
     *   allocateOrder -> isConfirmed()   -> allocated
     *   fulfilOrder   -> isAllocated()   -> fulfilled
     *   createInvoice -> isFulfilled()|isAllocated()|isConfirmed()|isInvoiced() -> invoiced
     *   cancelOrder   -> !isInvoiced()   -> cancelled
     *   resetToDraft  -> isCancelled()   -> draft
     */
    private const TRANSITIONS = [
        'draft' => ['confirmed', 'cancelled'],
        'confirmed' => ['allocated', 'cancelled', 'invoiced'],
        'allocated' => ['fulfilled', 'cancelled', 'invoiced'],
        'fulfilled' => ['invoiced', 'cancelled'],
        'invoiced' => ['invoiced'],   // repeated/partial invoicing
        'cancelled' => ['draft'],     // reset-to-draft
    ];

    public function transition(
        SalesOrder $order,
        string $toStatus,
        ?string $reason = null,
        ?string $source = null,
        ?string $idempotencyKey = null,
    ): SalesOrder {
        return DB::transaction(function () use ($order, $toStatus, $reason, $source, $idempotencyKey) {
            $fromStatus = $order->status;
            $actorId = TenantUserHelper::resolveTenantUserId(Auth::id());

            if (! in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [])) {
                throw new \InvalidArgumentException(
                    "Cannot transition sales order #{$order->id} from '{$fromStatus}' to '{$toStatus}'."
                );
            }

            if ($idempotencyKey) {
                $existing = SalesOrderStatusHistory::where('sales_order_id', $order->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $order->fresh();
                }
            }

            $order->update(['status' => $toStatus]);

            match ($toStatus) {
                'confirmed' => $order->update(['confirmed_by' => $actorId]),
                'allocated' => $order->update(['allocated_by' => $actorId]),
                'cancelled' => $order->update([
                    'cancelled_by' => $actorId,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]),
                'draft' => $order->update([
                    'cancellation_reason' => null,
                    'cancelled_by' => null,
                    'cancelled_at' => null,
                ]),
                default => null,
            };

            SalesOrderStatusHistory::create([
                'sales_order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actorId,
                'actor_role' => null,
                'source' => $source ?? 'admin',
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'transitioned_at' => now(),
            ]);

            return $order->fresh();
        });
    }

    public function confirm(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        return $this->transition($order, 'confirmed', $reason, 'admin');
    }

    public function allocate(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        return $this->transition($order, 'allocated', $reason, 'admin');
    }

    public function fulfil(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        return $this->transition($order, 'fulfilled', $reason, 'admin');
    }

    public function markInvoiced(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        return $this->transition($order, 'invoiced', $reason, 'admin');
    }

    public function cancel(SalesOrder $order, string $reason, ?string $source = null): SalesOrder
    {
        return $this->transition($order, 'cancelled', $reason, $source ?? 'admin');
    }

    /**
     * Issue #141: reset a cancelled order back to draft through the state
     * machine (cancelled -> draft), clearing cancellation data and the
     * per-line cancelled quantities. Callers must enforce the business guard
     * (no linked invoices) before calling.
     */
    public function resetToDraft(SalesOrder $order, ?string $reason = null, ?string $source = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason, $source) {
            $order = $this->transition($order, 'draft', $reason, $source ?? 'admin');

            foreach ($order->lines as $line) {
                $line->update(['quantity_cancelled' => 0]);
            }

            return $order->fresh();
        });
    }
}
