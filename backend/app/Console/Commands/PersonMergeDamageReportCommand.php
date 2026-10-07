<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\PersonMergeDamagePlanner;
use App\Support\ReportRedactor;
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
        {--json= : Also write the full report (every person and link) to this file}
        {--redact : Replace names, emails, phones and address text in the JSON file and review sheet (personal data)}
        {--review-sheet= : Also write a CSV review sheet: one row per link, with the person fingerprint and empty decision columns}';

    protected $description = 'Report-only list of contact people wrongly merged by the person-matching bug';

    /** @var list<array<string, mixed>> */
    private array $reports = [];

    public function handle(PersonMergeDamagePlanner $planner): int
    {
        // The command instance can be reused in one process; each run
        // reports only its own tenants.
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

        if ($sheet = $this->option('review-sheet')) {
            $this->writeReviewSheet($sheet, (bool) $this->option('redact'));
            $this->info("Review sheet written to {$sheet}");
        }

        return $exit;
    }

    /**
     * W6 step 4 review sheet: decisions are made per link, checked against
     * the person's fingerprint by the repair run.
     */
    private function writeReviewSheet(string $path, bool $redact): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['tenant_id', 'person_id', 'person_fingerprint', 'customer_contact_id', 'customer_id', 'contact_name', 'person_name', 'status', 'bug_signature', 'proposed_decision', 'decision', 'decided_by', 'decided_at', 'note']);
        foreach ($this->reports as $report) {
            foreach ($report['people'] as $person) {
                foreach ($person['links'] as $link) {
                    $proposed = match (true) {
                        $link['auto_split_eligible'] => 'split',
                        $link['status'] === 'ok' => 'keep',
                        default => 'review',
                    };
                    fputcsv($handle, [
                        $report['tenant_id'], $person['person_id'], $person['fingerprint'],
                        $link['customer_contact_id'], $link['customer_id'],
                        $redact ? ReportRedactor::MARK : $link['name'],
                        $redact ? ReportRedactor::MARK : $person['person_name'],
                        $link['status'], $link['bug_signature'] ? 'yes' : 'no', $proposed,
                        '', '', '', '',
                    ]);
                }
            }
        }
        fclose($handle);
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
