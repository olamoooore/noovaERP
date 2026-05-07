<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckLedgerIntegrity extends Command
{
    protected $signature = 'ledger:check-integrity
                            {--tenant=all : Tenant ID or "all" for every active tenant}';

    protected $description = 'Check accounting ledger integrity across tenants';

    public function handle(): int
    {
        $tenantOption = (string) $this->option('tenant');
        $tenants = $this->resolveTenants($tenantOption);

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found for the requested scope.');

            return Command::SUCCESS;
        }

        $rows = [];
        $violations = 0;

        foreach ($tenants as $tenant) {
            $tenantRows = $tenant->run(function () use ($tenant) {
                return $this->runChecksForTenant($tenant);
            });

            foreach ($tenantRows as $row) {
                $rows[] = $row;
                $violations += $row['violations'];
            }
        }

        $this->info(sprintf('Checked %d tenant(s).', $tenants->count()));
        $this->newLine();

        $this->table(
            ['Tenant', 'Check', 'Status', 'Details'],
            array_map(static fn (array $row) => [
                $row['tenant'],
                $row['check'],
                $row['status'],
                $row['details'],
            ], $rows)
        );

        $this->newLine();

        if ($violations > 0) {
            $this->error(sprintf('Ledger integrity violations found: %d', $violations));

            return Command::FAILURE;
        }

        $this->info('Ledger integrity is clean.');

        return Command::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    private function resolveTenants(string $tenantOption)
    {
        if ($tenantOption !== '' && $tenantOption !== 'all') {
            $tenant = Tenant::query()->where('id', $tenantOption)->first();

            if (! $tenant) {
                $this->error("Tenant not found: {$tenantOption}");

                return collect();
            }

            return collect([$tenant]);
        }

        return Tenant::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Tenant $tenant) => $this->isActiveTenant($tenant))
            ->values();
    }

    private function isActiveTenant(Tenant $tenant): bool
    {
        $data = is_array($tenant->data)
            ? $tenant->data
            : (json_decode((string) $tenant->data, true) ?: []);

        if (! empty($data['suspended'])) {
            return false;
        }

        return ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture())
            || ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
            || (! $tenant->trial_ends_at && ! $tenant->subscription_ends_at && empty($data['suspended']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runChecksForTenant(Tenant $tenant): array
    {
        $tenantLabel = sprintf('%s (%s)', $tenant->id, $tenant->name ?? 'unnamed');
        $rows = [];

        $trialBalance = $this->checkTrialBalance();
        $rows[] = [
            'tenant' => $tenantLabel,
            'check' => 'Trial balance',
            'status' => $this->statusLabel($trialBalance['ok']),
            'details' => sprintf('debit=%s credit=%s diff=%s', $trialBalance['debit'], $trialBalance['credit'], $trialBalance['difference']),
            'violations' => $trialBalance['ok'] ? 0 : 1,
        ];

        $orphans = $this->checkZeroLineEntries();
        $rows[] = [
            'tenant' => $tenantLabel,
            'check' => 'Zero-line entries',
            'status' => $this->statusLabel($orphans['count'] === 0),
            'details' => $orphans['count'] === 0
                ? 'none'
                : sprintf('%d orphan(s): %s', $orphans['count'], $orphans['ids']),
            'violations' => $orphans['count'],
        ];

        $unbalanced = $this->checkUnbalancedEntries();
        $rows[] = [
            'tenant' => $tenantLabel,
            'check' => 'Unbalanced entries',
            'status' => $this->statusLabel($unbalanced['count'] === 0),
            'details' => $unbalanced['count'] === 0
                ? 'none'
                : sprintf('%d entry(ies), max diff=%s', $unbalanced['count'], $unbalanced['max_diff']),
            'violations' => $unbalanced['count'],
        ];

        $duplicates = $this->checkDuplicateJournalEntryLinks();
        $rows[] = [
            'tenant' => $tenantLabel,
            'check' => 'Duplicate links',
            'status' => $this->statusLabel($duplicates['groups'] === 0),
            'details' => $duplicates['groups'] === 0
                ? 'none'
                : sprintf('%d group(s), %d extra row(s)', $duplicates['groups'], $duplicates['extra_rows']),
            'violations' => $duplicates['extra_rows'],
        ];

        return $rows;
    }

    private function checkTrialBalance(): array
    {
        $totals = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as debit, COALESCE(SUM(jel.credit), 0) as credit')
            ->first();

        $debit = (float) ($totals->debit ?? 0);
        $credit = (float) ($totals->credit ?? 0);
        $difference = abs($debit - $credit);

        return [
            'ok' => $difference <= 0.01,
            'debit' => number_format($debit, 2, '.', ','),
            'credit' => number_format($credit, 2, '.', ','),
            'difference' => number_format($difference, 2, '.', ','),
        ];
    }

    private function checkZeroLineEntries(): array
    {
        $entries = DB::table('journal_entries as je')
            ->leftJoin('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->select('je.id')
            ->selectRaw('COUNT(jel.id) as line_count')
            ->groupBy('je.id')
            ->havingRaw('COUNT(jel.id) = 0')
            ->orderBy('je.id')
            ->get();

        return [
            'count' => $entries->count(),
            'ids' => $entries->pluck('id')->take(10)->implode(', '),
        ];
    }

    private function checkUnbalancedEntries(): array
    {
        $entries = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->select('je.id')
            ->selectRaw('ABS(COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0)) as difference')
            ->groupBy('je.id')
            ->havingRaw('ABS(COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0)) > 0.01')
            ->orderByDesc('difference')
            ->get();

        return [
            'count' => $entries->count(),
            'max_diff' => number_format((float) ($entries->first()->difference ?? 0), 2, '.', ','),
        ];
    }

    private function checkDuplicateJournalEntryLinks(): array
    {
        $postDuplicates = DB::table('journal_entry_links')
            ->select('tenant_id', 'source_document_type', 'source_document_id', 'relation_type')
            ->selectRaw('COUNT(*) as link_count')
            ->groupBy('tenant_id', 'source_document_type', 'source_document_id', 'relation_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $postExtraRows = $postDuplicates->sum(fn ($duplicate) => max(0, ((int) $duplicate->link_count) - 1));

        $reversalDuplicates = DB::table('journal_entry_links')
            ->select('tenant_id', 'original_journal_entry_id', 'relation_type')
            ->selectRaw('COUNT(*) as link_count')
            ->whereNotNull('original_journal_entry_id')
            ->groupBy('tenant_id', 'original_journal_entry_id', 'relation_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $reversalExtraRows = $reversalDuplicates->sum(fn ($duplicate) => max(0, ((int) $duplicate->link_count) - 1));

        return [
            'groups' => $postDuplicates->count() + $reversalDuplicates->count(),
            'extra_rows' => $postExtraRows + $reversalExtraRows,
        ];
    }

    private function statusLabel(bool $ok): string
    {
        return $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
    }
}
