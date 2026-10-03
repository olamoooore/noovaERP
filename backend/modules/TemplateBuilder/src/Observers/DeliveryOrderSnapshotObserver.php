<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Observers;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\DeliveryOrder;
use Modules\TemplateBuilder\Services\DocumentSnapshotService;

/**
 * Issue #613 (TP3): a confirmed delivery order is an issued document — store
 * its immutable copy at the draft → confirmed transition.
 */
final class DeliveryOrderSnapshotObserver
{
    public function __construct(
        private readonly DocumentSnapshotService $snapshots,
    ) {}

    public function updated(DeliveryOrder $deliveryOrder): void
    {
        if (! $deliveryOrder->wasChanged('status') || $deliveryOrder->status !== 'confirmed') {
            return;
        }

        $id = (string) $deliveryOrder->id;

        // Confirmation may run inside a larger transaction; snapshot after it
        // commits (executes immediately when there is no open transaction).
        DB::afterCommit(fn () => $this->snapshots->snapshot('delivery_order', $id));
    }
}
