<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Illuminate\Support\Facades\DB;

class OrderLifecycleService
{
    private const TRANSITIONS = [
        'draft' => ['confirmed', 'cancelled'],
        'confirmed' => ['allocated', 'cancelled'],
        'allocated' => ['fulfilled', 'cancelled'],
        'fulfilled' => ['invoiced'],
        'invoiced' => [],
        'cancelled' => [],
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

            if (!in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [])) {
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
                'confirmed' => $order->update(['confirmed_by' => auth()->id()]),
                'allocated' => $order->update(['allocated_by' => auth()->id()]),
                'cancelled' => $order->update([
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]),
                default => null,
            };

            SalesOrderStatusHistory::create([
                'sales_order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => auth()->id(),
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
}