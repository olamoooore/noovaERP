<?php

namespace App\Console\Commands;

use App\Accounting\Events\AccountingOutboxPublished;
use App\Models\AccountingOutboxEvent;
use App\Models\Tenant;
use App\Platform\EventBus\DomainEventDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue noovaerp/NoovaERP#62 — accounting_outbox_events was write-only.
 *
 * PostingEngine inserts JournalPosted rows atomically with each ledger
 * mutation (ADR-005 §62), but nothing consumed them, so the second half
 * of the outbox pattern — reliable eventual publication — did not exist.
 *
 * This command polls unpublished rows and replays them onto the platform
 * Domain Event Bus (which provides persistence, subscriptions,
 * retry/dead-letter handling and replay). The outbox table is central,
 * while domain_event_records lives in the tenant schema, so rows are
 * processed per-tenant with tenancy initialized. A row is marked
 * published only after the bus accepted it; a publish failure keeps the
 * row for retry with an attempt counter + error, matching the
 * EventManagement outbox reference implementation.
 */
class PublishAccountingOutboxEvents extends Command
{
    protected $signature = 'accounting:publish-outbox
                            {--batch-size=50 : Maximum number of outbox rows to attempt per tenant per run}
                            {--max-attempts=5 : Rows failing more than this many times are left for manual review}';

    protected $description = 'Publish unpublished accounting outbox events onto the Domain Event Bus';

    public function handle(DomainEventDispatcher $dispatcher): int
    {
        $batchSize = max(1, (int) $this->option('batch-size'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));

        // Candidate ids grouped by tenant BEFORE touching tenancy context.
        $candidates = AccountingOutboxEvent::query()
            ->unpublished()
            ->where('publish_attempts', '<', $maxAttempts)
            ->select('id', 'tenant_id')
            ->orderBy('id')
            ->get()
            ->groupBy('tenant_id');

        $published = 0;
        $failed = 0;

        foreach ($candidates as $tenantId => $pairs) {
            $tenant = $tenantId !== null ? Tenant::find($tenantId) : null;

            if ($tenant) {
                tenancy()->initialize($tenant);
            }

            try {
                // Per-tenant transaction with FOR UPDATE SKIP LOCKED so
                // overlapping runs never double-publish and a mid-batch
                // crash rolls the published flags back (at-least-once).
                DB::transaction(function () use ($pairs, $dispatcher, $batchSize, &$published, &$failed) {
                    $ids = $pairs->pluck('id')->all();

                    $rows = AccountingOutboxEvent::query()
                        ->whereIn('id', $ids)
                        ->unpublished()
                        ->orderBy('id')
                        ->limit($batchSize)
                        ->lock('FOR UPDATE SKIP LOCKED')
                        ->get();

                    foreach ($rows as $row) {
                        try {
                            $dispatcher->dispatch(new AccountingOutboxPublished($row));
                            $row->markPublished();
                            $published++;
                        } catch (\Throwable $e) {
                            $row->markFailed($e->getMessage());
                            $failed++;
                            Log::warning('Accounting outbox publish failed; row retained for retry', [
                                'outbox_event_id' => $row->id,
                                'attempt' => $row->publish_attempts,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
            } finally {
                if ($tenant) {
                    tenancy()->end();
                }
            }
        }

        $this->info("Outbox publish: {$published} published, {$failed} failed.");

        return self::SUCCESS;
    }
}
