<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillPayment;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\SettlementRecognitionService;
use Illuminate\Console\Command;

/**
 * Backfills ACC-005B settlements for receipts/payments that predate the
 * recognition producer, so /accounting/settlements is not empty on
 * existing tenants.
 *
 * Tenant-safe: when a tenant context is already active (e.g. run through
 * `php artisan tenants:run settlements:recognise`), only that schema is
 * processed. Without a tenant context, every tenant is processed.
 */
class RecogniseSettlementsCommand extends Command
{
    protected $signature = 'settlements:recognise
        {--tenant= : Only this tenant ID (central run without --tenant processes all tenants)}
        {--chunk=200 : Records per batch}';

    protected $description = 'Recognise posted receipts and vendor payments into ACC-005B settlements (backfill)';

    public function handle(SettlementRecognitionService $recogniser): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        if ($tenantId = $this->option('tenant')) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                $this->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }

            $recognised = $tenant->run(fn () => $this->processCurrentSchema($recogniser, $chunk));
            $this->info("Tenant {$tenant->id}: {$recognised} settlement(s) recognised.");

            return self::SUCCESS;
        }

        // Already inside a tenant context (tenants:run or a bound test tenant).
        if (tenant()?->id) {
            $recognised = $this->processCurrentSchema($recogniser, $chunk);
            $this->info("{$recognised} settlement(s) recognised.");

            return self::SUCCESS;
        }

        $total = 0;
        foreach (Tenant::all() as $tenant) {
            try {
                $total += $tenant->run(fn () => $this->processCurrentSchema($recogniser, $chunk));
                $this->info("Tenant {$tenant->id}: recognised.");
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. {$total} settlement(s) recognised.");

        return self::SUCCESS;
    }

    private function processCurrentSchema(SettlementRecognitionService $recogniser, int $chunk): int
    {
        $count = 0;

        Payment::query()
            ->where(function ($query) {
                $query->whereNotNull('journal_entry_id')
                    ->orWhere('payment_status', '!=', 'recorded');
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($recogniser, &$count) {
                foreach ($payments as $payment) {
                    if ($recogniser->recognisePayment($payment)) {
                        $count++;
                    }
                }
            });

        BillPayment::query()
            ->whereNotNull('journal_entry_id')
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($recogniser, &$count) {
                foreach ($payments as $payment) {
                    if ($recogniser->recogniseBillPayment($payment)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
