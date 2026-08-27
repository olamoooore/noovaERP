<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\POS\Models\PosOfflineQueue;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;

class RecoverStuckPosOrders extends Command
{
    use IteratesTenants;

    protected $signature = 'pos:recover-stuck
                            {--dry-run : Show what would be recovered without making changes}
                            {--minutes=30 : Orders stuck for more than N minutes}
                            {--fix-syncing : Reset stuck syncing queue items back to pending}';

    protected $description = 'Recover POS orders and queue items stuck in intermediate states (per-tenant)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $minutes = (int) $this->option('minutes');
        $fixSyncing = $this->option('fix-syncing');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }

        return $this->forEachTenant(function ($tenant) use ($dryRun, $minutes, $fixSyncing) {
            $this->recoverStuckDraftOrders($dryRun, $minutes);
            $this->recoverOrphanedPayments($dryRun);

            if ($fixSyncing) {
                $this->recoverStuckSyncingItems($dryRun, $minutes);
            }

            $this->info("[tenant:{$tenant->id}] Recovery scan complete.");

            return 0;
        });
    }

    /**
     * Find draft orders that have a completed payment but status never updated to 'paid'.
     * This can happen if the payment completion service crashed after creating the payment
     * but before updating the order status.
     */
    protected function recoverStuckDraftOrders(bool $dryRun, int $minutes): void
    {
        $cutoff = now()->subMinutes($minutes);

        $stuckOrders = PosOrder::where('status', 'draft')
            ->where('created_at', '<', $cutoff)
            ->whereHas('payments', function ($q) {
                $q->whereIn('method', ['cash', 'card', 'store_credit', 'split'])
                    ->whereNull('refund_for_payment_id');
            })
            ->get();

        if ($stuckOrders->isEmpty()) {
            $this->info('No stuck draft orders with payments found.');

            return;
        }

        $this->warn("Found {$stuckOrders->count()} draft order(s) with completed payments.");

        foreach ($stuckOrders as $order) {
            $payment = $order->payments()
                ->whereIn('method', ['cash', 'card', 'store_credit', 'split'])
                ->whereNull('refund_for_payment_id')
                ->latest()
                ->first();

            $this->line("  Order #{$order->id}: has payment #{$payment->id} ({$payment->method}, {$payment->amount}) but status=draft");

            if (! $dryRun) {
                DB::transaction(function () use ($order, $payment) {
                    $order->update(['status' => 'paid']);

                    Log::warning('pos.recovery: draft order recovered to paid', [
                        'order_id' => $order->id,
                        'payment_id' => $payment->id,
                        'recovery_reason' => 'stuck_draft_with_payment',
                    ]);
                });

                $this->info("  ✓ Recovered order #{$order->id} → paid");
            }
        }
    }

    /**
     * Find POS payments that have no corresponding GL journal entry.
     * These are orphaned payments that need GL reconciliation.
     */
    protected function recoverOrphanedPayments(bool $dryRun): void
    {
        $orphaned = PosPayment::whereNull('journal_entry_id')
            ->whereIn('method', ['cash', 'card', 'store_credit'])
            ->whereNull('refund_for_payment_id')
            ->where('created_at', '<', now()->subMinutes(5)) // grace period
            ->get();

        if ($orphaned->isEmpty()) {
            $this->info('No orphaned payments (missing GL) found.');

            return;
        }

        $this->warn("Found {$orphaned->count()} payment(s) with no GL journal entry.");

        foreach ($orphaned as $payment) {
            $this->line("  Payment #{$payment->id}: order #{$payment->pos_order_id}, method={$payment->method}, amount={$payment->amount}");

            if (! $dryRun) {
                Log::error('pos.recovery: orphaned payment detected - manual GL reconciliation required', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->pos_order_id,
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                ]);
            }
        }

        if (! $dryRun) {
            $this->warn("Orphaned payments logged for manual GL reconciliation. Run 'pos:generate-missing-invoices' to attempt backfill.");
        }
    }

    /**
     * Reset queue items stuck in 'syncing' state back to 'pending' so they can be retried.
     * Items get stuck in 'syncing' if the sync process crashed mid-execution.
     */
    protected function recoverStuckSyncingItems(bool $dryRun, int $minutes): void
    {
        $cutoff = now()->subMinutes($minutes);

        $stuckItems = PosOfflineQueue::where('status', 'syncing')
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($stuckItems->isEmpty()) {
            $this->info('No stuck syncing queue items found.');

            return;
        }

        $this->warn("Found {$stuckItems->count()} queue item(s) stuck in 'syncing' state.");

        foreach ($stuckItems as $item) {
            $this->line("  Queue item #{$item->id}: operation={$item->operation_type}, stuck since {$item->updated_at}");

            if (! $dryRun) {
                DB::table('pos_offline_queue')
                    ->where('id', $item->id)
                    ->where('status', 'syncing')
                    ->update([
                        'status' => 'pending',
                        'updated_at' => now(),
                    ]);

                Log::warning('pos.recovery: stuck syncing item reset to pending', [
                    'queue_item_id' => $item->id,
                    'operation_type' => $item->operation_type,
                ]);

                $this->info("  ✓ Reset queue item #{$item->id} → pending");
            }
        }
    }
}
