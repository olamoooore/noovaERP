<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\ContactLinkingPlanner;
use App\Support\ReportRedactor;
use Illuminate\Console\Command;

/**
 * ADR-003B W6 pre-step — REPORT ONLY. Shows, per tenant, what linking the
 * unlinked customers and customer contacts to the Contact Master would do.
 * Writes nothing to the database; the optional --json file is for review.
 */
class ContactLinkingReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'contacts:linking-report
        {--tenant= : Report a single tenant id instead of every tenant}
        {--json= : Also write the full report (every row) to this file}
        {--redact : Replace names, emails, phones and address text in the JSON file (personal data)}';

    protected $description = 'Report-only plan for linking customers and their contacts to the Contact Master (ADR-003B W6)';

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    public function handle(ContactLinkingPlanner $planner): int
    {
        // The command instance can be reused (e.g. several calls in one
        // process); each run reports only its own tenants.
        $this->reports = [];
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
            $reports = $this->option('redact') ? ReportRedactor::redact($this->reports) : $this->reports;
            file_put_contents($path, json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->info("Full report written to {$path}");
        }

        return $exit;
    }

    private function reportTenant(ContactLinkingPlanner $planner, string $tenantId): int
    {
        $report = $planner->plan($tenantId);
        $this->reports[] = $report;

        $this->info("[tenant:{$tenantId}] REPORT ONLY — nothing was written.");
        foreach (['organisations' => 'Customers not linked to an organisation', 'people' => 'Customer contacts not linked to a person'] as $key => $title) {
            $this->line("  {$title}:");
            $rows = collect($report['summary'][$key])->except('total')->map(fn ($count, $action) => [$action, $count])->values()->all();
            $rows[] = ['total', $report['summary'][$key]['total']];
            $this->table(['Action', 'Rows'], $rows);
        }

        return self::SUCCESS;
    }
}
