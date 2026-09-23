<?php

namespace App\Console\Commands;

use App\Models\AccountingOutboxEvent;
use Illuminate\Console\Command;

/**
 * Issue #62 (HR62-01): governed recovery for dead-lettered accounting
 * outbox rows. Requeue resets the attempt budget so
 * `accounting:publish-outbox` picks the row up again, while the failure
 * history and requeue provenance stay recorded on the row.
 *
 * Usage:
 *   php artisan accounting:requeue-outbox 123            # a single row
 *   php artisan accounting:requeue-outbox --all-dead     # every dead-lettered row
 *   php artisan accounting:requeue-outbox --all-dead --tenant=<id>
 */
class RequeueAccountingOutboxEvents extends Command
{
    protected $signature = 'accounting:requeue-outbox
                            {id? : The accounting_outbox_events row id to requeue}
                            {--all-dead : Requeue every dead-lettered row}
                            {--tenant= : Limit --all-dead to one tenant id}';

    protected $description = 'Requeue dead-lettered accounting outbox events for publication';

    public function handle(): int
    {
        $query = AccountingOutboxEvent::query()->deadLettered();

        if ($this->option('tenant')) {
            $query->where('tenant_id', (string) $this->option('tenant'));
        }

        if ($this->argument('id')) {
            $query->whereKey((int) $this->argument('id'));
        } elseif (! $this->option('all-dead')) {
            $this->error('Specify an id or --all-dead.');

            return self::FAILURE;
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No dead-lettered accounting outbox rows matched.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $row->requeue();
        }

        $this->info("Requeued {$rows->count()} accounting outbox row(s).");

        return self::SUCCESS;
    }
}
