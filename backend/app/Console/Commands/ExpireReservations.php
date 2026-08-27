<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\StockReservationService;

/**
 * Expire overdue stock reservations and release reserved quantity.
 *
 * Per Pillar 1 P0-11 / Pillar 2 Part 1 §1.3: the inventory module
 * service touches tenant-schema-only tables
 * (`stock_reservations`). The scheduler runs this with the default
 * `search_path = 'public'`, so without per-tenant iteration the
 * command would either no-op or fail. We now iterate tenants.
 */
class ExpireReservations extends Command
{
    use IteratesTenants;

    protected $signature = 'inventory:expire-reservations';

    protected $description = 'Expire overdue stock reservations and release reserved quantity (per-tenant).';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $service = app(StockReservationService::class);

        return $this->forEachTenant(function ($tenant) use ($service, $startedAt) {
            $tenantStarted = microtime(true);

            try {
                $expiredCount = $service->expireOverdue();

                $durationMs = (int) round((microtime(true) - $tenantStarted) * 1000);

                Log::info('Reservation expiry command completed', [
                    'command' => 'inventory:expire-reservations',
                    'tenant_id' => $tenant->id,
                    'status' => 'success',
                    'expired_count' => $expiredCount,
                    'duration_ms' => $durationMs,
                ]);

                $this->info("[tenant:{$tenant->id}] Expired {$expiredCount} stock reservation(s).");

                return 0;
            } catch (\Throwable $e) {
                $durationMs = (int) round((microtime(true) - $tenantStarted) * 1000);

                Log::error('Failed to run reservation expiry command', [
                    'command' => 'inventory:expire-reservations',
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                    'duration_ms' => $durationMs,
                ]);

                $this->error("[tenant:{$tenant->id}] Reservation expiry command failed. Check logs for details.");

                return 1;
            }
        });
    }
}
