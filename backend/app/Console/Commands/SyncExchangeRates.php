<?php

namespace App\Console\Commands;

use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\Tenant;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Issue #102 — ExchangeRateService was real but never scheduled, so tenant
 * exchange rates went stale indefinitely unless manually triggered.
 *
 * Runs per tenant (schema-per-tenant): resolves the tenant's base currency
 * from company_settings, derives the target currencies from the tenant's
 * currency catalogue (or an explicit --currencies list) and syncs today's
 * rates through ExchangeRateService::syncRatesFromAPI().
 *
 * Failures are isolated per tenant (a missing API key or provider outage is
 * logged and skipped, never fatal for the schedule).
 */
class SyncExchangeRates extends Command
{
    protected $signature = 'exchange-rates:sync
                            {--base= : Base currency (defaults to the tenant company setting, then USD)}
                            {--currencies= : Comma-separated target currency codes (defaults to every currency except the base)}';

    protected $description = 'Sync tenant exchange rates from the configured provider';

    public function handle(ExchangeRateService $service): int
    {
        $synced = 0;
        $failed = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $base = $this->option('base')
                    ?: (CompanySettings::query()->value('base_currency') ?: 'USD');

                $targets = $this->option('currencies')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('currencies')))))
                    : Currency::query()->where('code', '!=', $base)->pluck('code')->all();

                if (empty($targets)) {
                    $this->line("Tenant {$tenant->id}: no target currencies to sync.");
                    continue;
                }

                $results = $service->syncRatesFromAPI($base, $targets);
                $ok = collect($results)->where('success', true)->count();

                $synced += $ok;
                $failed += count($results) - $ok;

                $this->info("Tenant {$tenant->id}: synced {$ok}/".count($targets)." rates for base {$base}.");
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Exchange-rate sync failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Exchange-rate sync complete: {$synced} synced, {$failed} failed.");

        return self::SUCCESS;
    }
}
