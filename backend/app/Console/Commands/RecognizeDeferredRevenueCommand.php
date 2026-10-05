<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\DeferredRevenueRecognitionService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Issue #882 (SA9) — release the earned portion of every active deferred
 * revenue schedule to the line's income account.
 *
 * Scheduled monthly in routes/console.php; iterates every tenant (schema-per-
 * tenant) through the shared IteratesTenants trait, and supports `--tenant`
 * for a single schema / test context.
 */
class RecognizeDeferredRevenueCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'accounting:recognize-deferred-revenue
        {--as-of= : Release date (defaults to today)}
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Release the deferred revenue earned by the as-of date to income (#882)';

    public function handle(DeferredRevenueRecognitionService $service): int
    {
        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'))
            : now();

        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->releaseTenant($service, $asOf, (string) $tenant->id));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: run against the current search_path (same fallback
            // as the weekly sales digest).
            return $this->releaseTenant($service, $asOf, $tenantId);
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->releaseTenant($service, $asOf, (string) $tenant->id));
    }

    private function releaseTenant(
        DeferredRevenueRecognitionService $service,
        CarbonInterface $asOf,
        string $tenantId,
    ): int {
        try {
            $summary = $service->releaseAsOf($asOf);

            $this->info(sprintf(
                '[tenant:%s] released %s across %d schedule(s).',
                $tenantId,
                number_format($summary['released'], 2, '.', ''),
                $summary['schedules'],
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Deferred revenue recognition failed for tenant', [
                'tenant_id' => $tenantId,
                'as_of' => $asOf->toDateString(),
                'error' => $e->getMessage(),
            ]);
            $this->error("[tenant:{$tenantId}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
