<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Sales\Services\WeeklySalesDigestService;

/**
 * Issue #606 — weekly per-salesperson sales digest.
 *
 * Runs once a week (scheduled in routes/console.php) over every tenant, and
 * inside each tenant only over the users who opted in. Each digest line is
 * deep-linked, entity-scoped and permission-filtered; delivery is WhatsApp
 * when the tenant has an active WhatsApp API key and the user has a phone,
 * else email. See WeeklySalesDigestService for the data sources.
 */
class SendWeeklySalesDigest extends Command
{
    use IteratesTenants;

    protected $signature = 'sales:send-weekly-digest
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Send the weekly per-salesperson sales digest (WhatsApp when available, else email) (#606)';

    public function handle(): int
    {
        [$periodStart, $periodEnd] = $this->previousWeekRange();

        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->digestTenant($tenant, $periodStart, $periodEnd));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: run against the current search_path (same fallback
            // as AnchorAuditChain).
            return $this->digestTenant($tenant ?? new Tenant(['id' => $tenantId]), $periodStart, $periodEnd);
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->digestTenant($tenant, $periodStart, $periodEnd));
    }

    /**
     * The previous completed calendar week (Monday–Sunday).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousWeekRange(): array
    {
        $currentWeekStart = now()->startOfWeek(Carbon::MONDAY);

        return [
            $currentWeekStart->copy()->subWeek(),
            $currentWeekStart->copy()->subDay(),
        ];
    }

    private function digestTenant(Tenant $tenant, Carbon $periodStart, Carbon $periodEnd): int
    {
        $tenantKey = (string) $tenant->getTenantKey();
        $service = app(WeeklySalesDigestService::class);

        $users = $service->optedInUsers($tenantKey);

        if ($users->isEmpty()) {
            $this->info("[tenant:{$tenantKey}] No opted-in users for the weekly sales digest.");

            return 0;
        }

        $counts = ['sent' => 0, 'already_sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($users as $user) {
            $outcome = $service->processUser($user, $tenantKey, $periodStart, $periodEnd);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

            if (in_array($outcome, ['sent', 'failed'], true)) {
                $this->line("[tenant:{$tenantKey}] {$user->email}: {$outcome}");
            }
        }

        $this->info(sprintf(
            '[tenant:%s] Weekly sales digest — sent: %d, already sent: %d, skipped: %d, failed: %d',
            $tenantKey,
            $counts['sent'],
            $counts['already_sent'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? 1 : 0;
    }
}
