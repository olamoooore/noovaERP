<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\TaxTrailJurisdictionPlanner;
use Illuminate\Console\Command;

/**
 * REPORT ONLY. Posted tax recorded under a jurisdiction other than the
 * recording entity's own (missing, unrecognised text or a foreign code), by
 * period, direction, document type and currency, with filed periods marked.
 * Writes nothing; for the tax agent's review.
 */
class TaxTrailJurisdictionReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'tax:trail-jurisdiction-report
        {--tenant= : Report a single tenant id instead of every tenant}
        {--json= : Also write the full report (every group) to this file}';

    protected $description = 'Report-only money impact: posted tax recorded outside the recording entity\'s own jurisdiction';

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    public function handle(TaxTrailJurisdictionPlanner $planner): int
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

    private function reportTenant(TaxTrailJurisdictionPlanner $planner, string $tenantId): int
    {
        $report = $planner->plan($tenantId);
        $this->reports[] = $report;
        $summary = $report['summary'];

        $this->info("[tenant:{$tenantId}] REPORT ONLY — nothing was written. Home jurisdiction: {$report['tenant_home_jurisdiction']}");
        $this->line("  Trail rows checked: {$summary['rows_checked']}; recorded outside home: {$summary['rows_outside_home']}");
        $this->line('  Periods affected: '.(implode(', ', $summary['periods_affected']) ?: 'none'));
        $this->line('  Of which FILED: '.(implode(', ', $summary['filed_periods_affected']) ?: 'none'));
        $this->table(
            ['Direction | currency', 'Tax outside home'],
            collect($summary['tax_outside_home'])->map(fn ($amount, $key) => [$key, number_format((float) $amount, 2)])->values()->all()
        );

        return self::SUCCESS;
    }
}
