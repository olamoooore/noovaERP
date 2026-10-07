<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\ContactLinkingPlanner;
use App\Support\ReportRedactor;
use App\Support\SpreadsheetCell;
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
        {--redact : Replace names, emails, phones and address text in the JSON file and review sheet (personal data)}
        {--review-sheet= : Also write a CSV review sheet: one row per planned customer and contact, with its fingerprint, allowed decisions and empty decision columns}';

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

        if ($sheet = $this->option('review-sheet')) {
            $this->writeReviewSheet($sheet, (bool) $this->option('redact'));
            $this->info("Review sheet written to {$sheet}");
        }

        return $exit;
    }

    /**
     * W6 step 3 review sheet. The future linking run reads the decision
     * columns and checks each row's fingerprint (source and target).
     */
    private function writeReviewSheet(string $path, bool $redact): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['tenant_id', 'kind', 'row_id', 'fingerprint', 'name', 'action', 'reason', 'targets', 'depends_on', 'allowed_decisions', 'proposed_decision', 'decision', 'decision_target', 'decided_by', 'decided_at', 'note']);
        foreach ($this->reports as $report) {
            // A dependent row is proposed only when everything it depends on
            // is proposed to create/link/keep (rows are written in run order).
            $proposals = [];
            foreach (['organisation' => $report['organisations'], 'person' => $report['people']] as $kind => $rows) {
                foreach ($rows as $row) {
                    [$allowed, $proposed] = $this->decisionsFor($row);
                    $dependsOn = $row['depends_on'] ?? [];
                    foreach ($dependsOn as $dependency) {
                        if (! in_array($proposals[$dependency] ?? '', ['create', 'link', 'keep'], true)) {
                            $proposed = '';
                        }
                    }
                    $rowId = $kind === 'organisation' ? $row['customer_id'] : $row['customer_contact_id'];
                    $proposals[$kind.':'.$rowId] = $proposed;
                    fputcsv($handle, [
                        $report['tenant_id'],
                        $kind,
                        $rowId,
                        $row['fingerprint'],
                        $redact ? ReportRedactor::MARK : SpreadsheetCell::escape($kind === 'organisation' ? $row['customer_name'] : $row['name']),
                        $row['action'],
                        $row['reason'] ?? '',
                        implode('|', ContactLinkingPlanner::targetIds($row)),
                        implode('|', $dependsOn),
                        implode('|', $allowed),
                        $proposed,
                        '', '', '', '', '',
                    ]);
                }
            }
        }
        fclose($handle);
    }

    /**
     * Allowed decisions per action/reason, and the proposal ('' = a person
     * must decide).
     *
     * @return array{0: list<string>, 1: string}
     */
    private function decisionsFor(array $row): array
    {
        return match (true) {
            $row['action'] === ContactLinkingPlanner::ACTION_KEEP_MERGED_CUSTOMER => [['keep'], 'keep'],
            ($row['reason'] ?? null) === 'linked_to_merged_duplicate' => [['survivor', 'skip'], ''],
            ($row['reason'] ?? null) === 'linked_contact_deleted' => [['restore', 'create', 'skip'], ''],
            $row['action'] === ContactLinkingPlanner::ACTION_CREATE_ORGANISATION,
            $row['action'] === ContactLinkingPlanner::ACTION_CREATE_PERSON => [['create', 'skip'], 'create'],
            $row['action'] === ContactLinkingPlanner::ACTION_CREATE_PERSON_WITH_CANDIDATE => [['create', 'link', 'skip'], 'create'],
            $row['action'] === ContactLinkingPlanner::ACTION_AUTO_LINK => [['link', 'create', 'skip'], 'link'],
            default => [['create', 'link', 'skip'], ''],
        };
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

        $parties = $report['customer_vendor_parties']['summary'];
        $this->line("  Customer + vendor companies: {$parties['already_shared']} on one shared record, {$parties['separate_records']} customer/vendor pairs on separate records.");

        return self::SUCCESS;
    }
}
