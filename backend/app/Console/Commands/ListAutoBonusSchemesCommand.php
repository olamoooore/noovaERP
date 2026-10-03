<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\AutoBonusSchemeReport;
use Illuminate\Console\Command;

/**
 * Issue #564 (BS3) AC4 — list-only clean-up helper.
 *
 * Lists `Auto: *` bonus schemes that have no rules (no rows in
 * `bonus_scheme_products`), together with their customer assignments, so an
 * operator can review and retire the schemes imports auto-created from
 * medical-case tags. Read-only by construction: it only ever reads and
 * prints — it never modifies, retires or deletes anything.
 *
 * Usage:
 *   php artisan bonus-schemes:list-auto
 *   php artisan bonus-schemes:list-auto --tenant=<id>
 *   php artisan bonus-schemes:list-auto --format=json
 */
class ListAutoBonusSchemesCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'bonus-schemes:list-auto
        {--tenant= : Run for a single tenant id}
        {--format=table : Output format (table|json)}';

    protected $description = 'List `Auto: *` bonus schemes with no rules and their assignments (#564) — read-only';

    private int $scannedTenants = 0;

    private int $listedSchemes = 0;

    public function __construct(
        private readonly AutoBonusSchemeReport $report,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->scannedTenants = 0;
        $this->listedSchemes = 0;

        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }

            return (int) $tenant->run(fn () => $this->renderReport($tenant));
        }

        $exit = $this->forEachTenant(fn (Tenant $tenant) => $this->renderReport($tenant));

        if ($this->scannedTenants === 0) {
            $this->info('No tenant schemas found to scan.');
        } else {
            $this->info(sprintf(
                'Scanned %d tenant schema(s); %d rule-less `Auto:` scheme(s) listed.',
                $this->scannedTenants,
                $this->listedSchemes,
            ));
        }

        $this->comment('Read-only listing — nothing has been modified. Retire unwanted schemes manually after review.');

        return $exit;
    }

    protected function renderReport(Tenant $tenant): int
    {
        $this->scannedTenants++;

        $rows = $this->report->rows();
        $this->listedSchemes += count($rows);

        if ($rows === []) {
            return self::SUCCESS;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'tenant_id' => $tenant->id,
                'schemes' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Auto-created bonus schemes with no rules — tenant {$tenant->id}");

        $this->table(
            ['ID', 'Code', 'Name', 'Status', 'Start', 'End', 'Assignments'],
            collect($rows)->map(fn (array $row): array => [
                $row['scheme_id'],
                (string) ($row['scheme_code'] ?? ''),
                $row['name'],
                (string) ($row['status'] ?? ''),
                (string) ($row['start_date'] ?? '—'),
                (string) ($row['end_date'] ?? '—'),
                $row['assignment_count'],
            ])->all()
        );

        foreach ($rows as $row) {
            foreach ($row['assignments'] as $assignment) {
                $this->line(sprintf(
                    '  scheme #%d → customer #%d %s (%s)%s',
                    $row['scheme_id'],
                    $assignment['customer_id'],
                    $assignment['customer_name'] ?? '—',
                    (string) ($assignment['status'] ?? ''),
                    $assignment['remarks'] !== null ? ' — '.$assignment['remarks'] : '',
                ));
            }
        }

        return self::SUCCESS;
    }
}
