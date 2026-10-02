<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\SalesLegalEntityBackfillService;
use Illuminate\Console\Command;

/**
 * Issue #875 (P0, SA1) backfill report — read-only.
 *
 * Recorded decision (2026-10-02): single-entity tenants are backfilled
 * automatically by the tenant migration; multi-entity tenants get this
 * document → proposed entity mapping report and the client approves it BEFORE
 * anything is written. Unmatched rows stay blank and are listed here.
 *
 * Usage:
 *   php artisan accounting:sales-legal-entity-mapping-report
 *   php artisan accounting:sales-legal-entity-mapping-report --tenant=<id>
 *   php artisan accounting:sales-legal-entity-mapping-report --format=json
 */
class SalesLegalEntityMappingReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'accounting:sales-legal-entity-mapping-report
        {--tenant= : Run for a single tenant id}
        {--format=table : Output format (table|json)}';

    protected $description = 'Read-only report mapping unmapped Sales documents and journal entries to a proposed legal entity (#875)';

    public function __construct(
        private readonly SalesLegalEntityBackfillService $backfill,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }

            return (int) $tenant->run(fn () => $this->renderReport($tenant));
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->renderReport($tenant));
    }

    protected function renderReport(Tenant $tenant): int
    {
        $report = $this->backfill->mappingReport();

        $this->newLine();
        $this->info("Sales legal-entity mapping report — tenant {$tenant->id}");
        $this->line(sprintf(
            'Active entities: %d%s',
            count($report['active_entity_ids']),
            $report['single_entity'] ? ' (single-entity tenant — migration backfills automatically)' : ' (multi-entity — approval required before applying)'
        ));

        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = collect($report['proposed'])->map(fn (array $row) => [
            $row['document_type'],
            $row['document_id'],
            (string) ($row['reference'] ?? ''),
            $row['proposed_entity_id'] !== null ? (string) $row['proposed_entity_id'] : '—',
            $row['reason'],
            $row['status'],
        ])->values()->all();

        if ($rows === []) {
            $this->info('No unmapped Sales documents or journal entries. Nothing to review.');

            return self::SUCCESS;
        }

        $this->table(['Document', 'ID', 'Reference', 'Proposed Entity', 'Reason', 'Status'], $rows);

        $this->line(sprintf(
            'Proposed: %d — Unmatched (stay blank): %d',
            $report['counts']['proposed'],
            $report['counts']['unmatched']
        ));

        if (! empty($report['unmatched'])) {
            $this->warn('Unmatched rows (no entity resolvable — review manually):');
            foreach ($report['unmatched'] as $row) {
                $this->line(sprintf(
                    '  - %s #%d %s: %s',
                    $row['document_type'],
                    $row['document_id'],
                    (string) ($row['reference'] ?? ''),
                    $row['reason']
                ));
            }
        }

        $this->comment('This report is read-only. Nothing has been written. Multi-entity mappings require client approval before applying.');

        return self::SUCCESS;
    }
}
