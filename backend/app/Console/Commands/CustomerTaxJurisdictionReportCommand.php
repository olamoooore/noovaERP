<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\CustomerTaxJurisdictionPlanner;
use Illuminate\Console\Command;

/**
 * ADR-003B W6 step 2 — REPORT ONLY. Lists customers whose tax jurisdiction
 * is derived from unrecognised country text, or would change if tax used the
 * shared customer address rule. Writes nothing; for the tax agent's review.
 * The JSON holds customer ids, codes and countries only (no personal data).
 */
class CustomerTaxJurisdictionReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'tax:customer-jurisdiction-report
        {--tenant= : Report a single tenant id instead of every tenant}
        {--json= : Also write the full report (every flagged customer) to this file}';

    protected $description = 'Report-only list of customers and vendors whose tax jurisdiction comes from unrecognised country text, a non-default address, or would change under the shared address rule';

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    public function handle(CustomerTaxJurisdictionPlanner $planner): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);
            $exit = ($tenant !== null && $this->schemaExists($tenant->getSchemaName()))
                ? (int) $tenant->run(fn () => $this->reportTenant($planner, $tenantId))
                : $this->reportTenant($planner, (string) ($tenant?->getTenantKey() ?? $tenantId));
        } else {
            $exit = $this->forEachTenant(fn (Tenant $tenant) => $this->reportTenant($planner, (string) $tenant->getTenantKey()));
        }

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($this->reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->info("Full report written to {$path}");
        }

        return $exit;
    }

    private function reportTenant(CustomerTaxJurisdictionPlanner $planner, string $tenantId): int
    {
        $report = $planner->plan($tenantId);
        $this->reports[] = $report;

        $this->info("[tenant:{$tenantId}] REPORT ONLY — nothing was written.");
        $this->table(
            ['Measure', 'Customers'],
            collect($report['summary'])->except('unrecognised_values')->map(fn ($count, $key) => [$key, $count])->values()->all()
        );
        $this->table(
            ['Measure', 'Vendors'],
            collect($report['vendor_summary'])->except('unrecognised_values')->map(fn ($count, $key) => [$key, $count])->values()->all()
        );

        return self::SUCCESS;
    }
}
