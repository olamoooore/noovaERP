<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\ContactAddressBackfillPlanner;
use App\Support\ReportRedactor;
use Illuminate\Console\Command;

/**
 * ADR-003B W6 §4 step 1 — REPORT ONLY. Shows, per tenant, what moving the
 * customer addresses into the Contact Master would do. Writes nothing to the
 * database; the optional --json file is the artefact for review.
 */
class ContactAddressBackfillReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'contacts:address-backfill-report
        {--tenant= : Report a single tenant id instead of every tenant}
        {--json= : Also write the full report (every row) to this file}
        {--redact : Replace names, emails, phones and address text in the JSON file (personal data)}';

    protected $description = 'Report-only plan for moving customer addresses into the Contact Master (ADR-003B W6)';

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    public function handle(ContactAddressBackfillPlanner $planner): int
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
            $reports = $this->option('redact') ? ReportRedactor::redact($this->reports) : $this->reports;
            file_put_contents($path, json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->info("Full report written to {$path}");
        }

        return $exit;
    }

    private function reportTenant(ContactAddressBackfillPlanner $planner, string $tenantId): int
    {
        $report = $planner->plan($tenantId);
        $this->reports[] = $report;

        $this->info("[tenant:{$tenantId}] REPORT ONLY — nothing was written.");
        $summary = $report['summary'];
        $this->line("  Customers: {$summary['customers']} (not linked to an organisation: {$summary['customers_without_organisation']})");
        $this->line("  medical_cases rows pointing at customer_addresses: {$summary['medical_case_references']}");

        foreach (['customer_addresses' => 'Saved customer addresses', 'legacy_text_addresses' => 'Older text addresses', 'person_addresses' => 'Addresses on people'] as $key => $title) {
            $this->line("  {$title}:");
            $rows = collect($summary[$key])->except('total')->map(fn ($count, $action) => [$action, $count])->values()->all();
            $rows[] = ['total', $summary[$key]['total']];
            $this->table(['Action', 'Rows'], $rows);
        }

        return self::SUCCESS;
    }
}
