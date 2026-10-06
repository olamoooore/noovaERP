<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\PersonMergeDamagePlanner;
use Illuminate\Console\Command;

/**
 * REPORT ONLY. Lists Contact Master people that were wrongly shared between
 * customer contacts by the person-matching bug fixed in 9e389eb. Writes
 * nothing; the optional --json file is for review before any repair plan.
 */
class PersonMergeDamageReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'contacts:person-merge-damage-report
        {--tenant= : Report a single tenant id instead of every tenant}
        {--json= : Also write the full report (every person and link) to this file}';

    protected $description = 'Report-only list of contact people wrongly merged by the person-matching bug';

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    public function handle(PersonMergeDamagePlanner $planner): int
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

    private function reportTenant(PersonMergeDamagePlanner $planner, string $tenantId): int
    {
        $report = $planner->plan($tenantId);
        $this->reports[] = $report;

        $this->info("[tenant:{$tenantId}] REPORT ONLY — nothing was written.");
        $this->table(['Measure', 'Count'], collect($report['summary'])->map(fn ($count, $key) => [$key, $count])->values()->all());

        return self::SUCCESS;
    }
}
