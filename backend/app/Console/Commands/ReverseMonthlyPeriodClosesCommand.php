<?php

namespace App\Console\Commands;

use App\Models\FiscalPeriod;
use App\Models\Tenant;
use App\Services\PeriodCloseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #761 (A1, decision 2026-10-02, option b) — repair for tenants that already
 * closed periods under the old model where every monthly close posted a
 * `period_close` journal entry and zeroed the month's P&L.
 *
 * Reverses those legacy monthly closing entries through the authoritative
 * ACC-002 reversal path and keeps the period locks. Fiscal-year-end closing
 * entries (the decision's one legitimate close) are left alone. Reports by
 * default; pass --apply to execute.
 */
class ReverseMonthlyPeriodClosesCommand extends Command
{
    protected $signature = 'accounting:reverse-monthly-period-closes
        {--apply : Execute the reversal (default is a dry-run report)}';

    protected $description = 'Reverse legacy monthly period_close entries, keeping period locks (#761 repair).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $totalEntries = 0;
        $totalPeriods = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $periods = FiscalPeriod::query()
                    ->whereIn('id', function ($query) {
                        $query->select('je.period_id')
                            ->from('journal_entries as je')
                            ->where('je.type', 'period_close')
                            ->where('je.is_active_version', true)
                            ->whereNotNull('je.period_id');
                    })
                    ->orderBy('period_start')
                    ->get()
                    // Keep the one legitimate fiscal-year-end close.
                    ->reject(fn (FiscalPeriod $period) => $period->period_end
                        && $period->period_end->toDateString() === sprintf('%04d-12-31', (int) $period->fiscal_year));

                foreach ($periods as $period) {
                    $entryCount = DB::table('journal_entries')
                        ->where('type', 'period_close')
                        ->where('is_active_version', true)
                        ->where('period_id', $period->id)
                        ->count();

                    if ($entryCount === 0) {
                        continue;
                    }

                    $totalPeriods++;
                    $totalEntries += $entryCount;

                    $this->line(sprintf(
                        '[%s] %s (%s..%s): %d legacy monthly closing entr%s',
                        $tenant->id,
                        $period->code,
                        $period->period_start?->toDateString(),
                        $period->period_end?->toDateString(),
                        $entryCount,
                        $entryCount === 1 ? 'y' : 'ies'
                    ));

                    if ($apply) {
                        $reversed = app(PeriodCloseService::class)->reverseClosingEntries(
                            $period,
                            'Legacy monthly closing entry reversed — #761: a period close is a lock only',
                            'PERIOD_CLOSE_REPAIR',
                        );

                        $this->info("  reversed: {$reversed}; period status kept: {$period->status}");
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Monthly closing-entry repair failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        if ($totalEntries === 0) {
            $this->info('No legacy monthly closing entries found.');

            return self::SUCCESS;
        }

        $this->info($apply
            ? "Reversed {$totalEntries} legacy monthly closing entry(ies) across {$totalPeriods} period(s); locks were kept."
            : "{$totalEntries} legacy monthly closing entry(ies) across {$totalPeriods} period(s) found — dry run, pass --apply to reverse.");

        return self::SUCCESS;
    }
}
