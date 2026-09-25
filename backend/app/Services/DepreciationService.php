<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DraftJournalRequest;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\DepreciationSchedule;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Depreciation Service
 * Handles depreciation calculations and journal entry generation
 */
class DepreciationService
{
    protected $postingEngine;

    public function __construct(
        PostingEngineInterface $postingEngine,
    ) {
        $this->postingEngine = $postingEngine;
    }

    /**
     * Calculate depreciation for a period
     *
     * @param  string  $periodDate  - Date in format Y-m-d (e.g., '2024-01-31')
     * @param  array|null  $assetIds  - Specific asset IDs or null for all active assets
     */
    public function calculateDepreciationForPeriod(string $periodDate, ?array $assetIds = null): array
    {
        // A depreciation run builds the complete useful-life schedule. The
        // requested date is retained as the run context, but future periods
        // are intentionally created as drafts for review/posting later.
        $periodDate = Carbon::parse($periodDate)->endOfMonth();
        $results = [];

        // Get active assets
        $query = FixedAsset::where('status', 'active')
            ->where('is_active', true)
            ->where('depreciation_start_date', '<=', $periodDate);

        if ($assetIds) {
            $query->whereIn('id', $assetIds);
        }

        $assets = $query->get();

        foreach ($assets as $asset) {
            try {
                $start = Carbon::parse($asset->depreciation_start_date)->startOfMonth();
                $months = ($asset->useful_life_years ?? 0) * 12;
                if ($months < 1) {
                    throw new \InvalidArgumentException('Useful life must be at least one month.');
                }

                // Issue #64 TOCTOU guard: wrap the schedule read-then-create
                // loop in a DB::transaction after locking the FixedAsset row
                // so concurrent requests for the same asset serialise on the
                // asset lock; the unique (asset_id, period_date) index
                // backstops any race that slips through.
                $period = $start->copy();
                $scheduleEnd = $start->copy()->addMonths($months - 1)->endOfMonth();
                $created = 0;
                $pending = 0;
                $periodErrors = [];

                DB::transaction(function () use ($asset, &$period, $scheduleEnd, &$created, &$pending, &$periodErrors) {
                    FixedAsset::where('id', $asset->id)->lockForUpdate()->first();

                    while ($period->lte($scheduleEnd)) {
                        $periodEnd = $period->copy()->endOfMonth();

                        // Month-bucket lookup: this method stores end-of-month
                        // dates while generateScheduleForAsset() stores
                        // start-of-month dates, so an exact-date check misses
                        // rows created by the other entry point and duplicates
                        // the whole schedule (HR216 regression).
                        $existing = DepreciationSchedule::where('asset_id', $asset->id)
                            ->whereBetween('period_date', [
                                $period->copy()->startOfMonth()->toDateString(),
                                $periodEnd->toDateString(),
                            ])
                            ->first();

                        if (! $existing) {
                            $draftScheduledBefore = (float) DepreciationSchedule::where('asset_id', $asset->id)
                                ->where('is_posted', false)
                                ->sum('depreciation_amount');
                            $projectedAccumulated = (float) $asset->accumulated_depreciation + $draftScheduledBefore;
                            $remaining = (float) $asset->purchase_cost - (float) $asset->salvage_value - $projectedAccumulated;
                            if ($remaining <= 0.01) {
                                break;
                            }

                            // Issue #162: thread the running accumulated
                            // depreciation into the period calculation.
                            $amount = min($this->calculateDepreciationAmount($asset, $period, $projectedAccumulated), $remaining);
                            $amount = $this->prorateFirstPeriodAmount($asset, $period, $amount);
                            if ($amount <= 0) {
                                break;
                            }
                            $accumulated = $projectedAccumulated + $amount;
                            $bookValue = (float) $asset->purchase_cost - $accumulated;
                            $schedule = DepreciationSchedule::create([
                                'asset_id' => $asset->id,
                                'period_date' => $periodEnd->toDateString(),
                                'depreciation_amount' => $amount,
                                'accumulated_depreciation' => $accumulated,
                                'book_value' => max($bookValue, (float) $asset->salvage_value),
                                'is_posted' => false,
                            ]);

                            // Draft-journal creation can legitimately fail when
                            // the fiscal period is closed/locked (e.g. a
                            // historical asset whose useful life predates the
                            // current open period) or when no period exists.
                            // KEEP the schedule row unposted so the amortisation
                            // plan is visible and postPendingScheduleEntries()
                            // can create the journals once a period opens —
                            // matching generateScheduleForAsset()'s behaviour.
                            //
                            // NOTE: this method deliberately leaves is_posted =
                            // false on SUCCESS too — the running accumulation
                            // below (and $draftScheduledBefore) treats every
                            // row of the current run as an unposted draft.
                            // Rows with a journal_entry_id are "scheduled";
                            // rows without one are "pending posting".
                            try {
                                $journalEntry = $this->createDepreciationDraftJournalEntry($asset, $schedule, $periodEnd);
                                $schedule->update(['journal_entry_id' => $journalEntry->id]);
                                $created++;
                            } catch (\Throwable $e) {
                                $pending++;
                                $periodErrors[] = [
                                    'period' => $periodEnd->toDateString(),
                                    'message' => $e->getMessage(),
                                    'schedule_id' => $schedule->id,
                                    'retry_endpoint' => "/api/v1/assets/{$asset->id}/post-schedule",
                                ];
                            }
                        }
                        $period->addMonth();
                    }

                    // Final-period true-up: a mid-month start prorates the
                    // first period, so the whole-month charges no longer sum
                    // to the depreciable base by the last scheduled month.
                    // Charge the remainder in one final period so the asset
                    // fully depreciates (standard fixed-asset practice).
                    $scheduledTotal = (float) DepreciationSchedule::where('asset_id', $asset->id)
                        ->sum('depreciation_amount');
                    $trueUp = round((float) $asset->purchase_cost - (float) $asset->salvage_value - $scheduledTotal, 2);

                    if ($trueUp > 0.01) {
                        $trueUpPeriod = $period->copy()->endOfMonth();
                        $schedule = DepreciationSchedule::create([
                            'asset_id' => $asset->id,
                            'period_date' => $trueUpPeriod->toDateString(),
                            'depreciation_amount' => $trueUp,
                            'accumulated_depreciation' => round($scheduledTotal + $trueUp, 2),
                            'book_value' => max((float) $asset->purchase_cost - $scheduledTotal - $trueUp, (float) $asset->salvage_value),
                            'is_posted' => false,
                        ]);

                        try {
                            $journalEntry = $this->createDepreciationDraftJournalEntry($asset, $schedule, $trueUpPeriod);
                            $schedule->update(['journal_entry_id' => $journalEntry->id]);
                            $created++;
                        } catch (\Throwable $e) {
                            $pending++;
                            $periodErrors[] = [
                                'period' => $trueUpPeriod->toDateString(),
                                'message' => $e->getMessage(),
                                'schedule_id' => $schedule->id,
                                'retry_endpoint' => "/api/v1/assets/{$asset->id}/post-schedule",
                            ];
                        }
                    }
                });

                $totalScheduled = $created + $pending;

                if ($totalScheduled === 0) {
                    $message = 'No missing depreciation periods.';
                } else {
                    $message = "Created {$totalScheduled} depreciation schedule entr".($totalScheduled === 1 ? 'y' : 'ies').'.';
                    if ($pending > 0) {
                        $message .= " {$pending} period(s) could not be posted to the ledger (fiscal period closed or not open) — the schedule is saved and can be posted once the period is open.";
                    }
                }

                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => $totalScheduled > 0 ? 'success' : 'skipped',
                    'message' => $message,
                    'schedule_count' => $totalScheduled,
                    'draft_count' => $created,
                    'pending_posting' => $pending,
                    'errors' => $periodErrors,
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Generate the full depreciation schedule for a single asset and create
     * a journal entry for each period.
     *
     * For a 3-year life with monthly depreciation this produces 36 entries;
     * with annual posting 3 entries; with weekly posting 156 entries. The
     * period frequency is taken from $asset->depreciation_frequency
     * ('monthly' | 'annually' | 'weekly' | 'daily'), defaulting to 'monthly'.
     *
     * Behaviour:
     *  - One DepreciationSchedule row per period (idempotent: existing
     *    period entries are skipped, not duplicated)
     *  - One JournalEntry per period, linked to the schedule row
     *  - Asset.accumulated_depreciation and asset.book_value are advanced
     *    cumulatively, exactly the same as a real period run
     *  - Stops at salvage value (no entry can drive book_value below it)
     *
     * @return array summary of generated/skipped entries
     */
    public function generateScheduleForAsset(int $assetId): array
    {
        $asset = FixedAsset::findOrFail($assetId);
        $startDate = $asset->depreciation_start_date
            ? Carbon::parse($asset->depreciation_start_date)->startOfDay()
            : ($asset->acquisition_date ? Carbon::parse($asset->acquisition_date)->startOfDay() : Carbon::now()->startOfDay());

        $periodsTotal = $this->expectedPeriodCount($asset);
        $frequency = $asset->depreciation_frequency ?? 'monthly';
        $results = [
            'asset_id' => $asset->id,
            'asset_name' => $asset->name,
            'method' => $asset->depreciation_method,
            'frequency' => $frequency,
            'periods_planned' => $periodsTotal,
            'generated' => 0,
            'skipped_existing' => 0,
            'stopped_salvage' => 0,
            'pending_posting' => 0,
            'errors' => [],
        ];

        $cumulative = (float) $asset->accumulated_depreciation;
        $bookValue = (float) $asset->book_value;

        for ($i = 0; $i < $periodsTotal; $i++) {
            $periodDate = $this->advancePeriod($startDate, $frequency, $i);

            // Skip if a schedule row already exists for this period. For
            // monthly assets use a month-bucket match: this method stores
            // start-of-month dates while calculateDepreciationForPeriod()
            // stores end-of-month dates, so an exact-date check would
            // duplicate the schedule when both entry points are used.
            $existingQuery = DepreciationSchedule::where('asset_id', $asset->id);

            if ($frequency === 'monthly') {
                $existingQuery->whereBetween('period_date', [
                    $periodDate->copy()->startOfMonth()->toDateString(),
                    $periodDate->copy()->endOfMonth()->toDateString(),
                ]);
            } else {
                $existingQuery->where('period_date', $periodDate->format('Y-m-d'));
            }

            $existing = $existingQuery->first();

            if ($existing) {
                $results['skipped_existing']++;

                // Issue #162: advance the running totals past periods that were
                // already generated, otherwise resuming a partially-generated
                // declining-balance schedule would compute later periods from
                // too low an accumulated depreciation (i.e. too high a charge).
                $cumulative = round($cumulative + (float) $existing->depreciation_amount, 2);
                $bookValue = round((float) $asset->purchase_cost - $cumulative, 2);

                continue;
            }

            // Stop once accumulated reaches the depreciable base (cost - salvage)
            $depreciableBase = (float) $asset->purchase_cost - (float) $asset->salvage_value;
            $remaining = $depreciableBase - $cumulative;

            if ($remaining <= 0.01) {
                $results['stopped_salvage']++;
                break;
            }

            try {
                $amount = $this->calculateDepreciationAmount($asset, $periodDate, $cumulative);
                $amount = $this->prorateFirstPeriodAmount($asset, $periodDate, $amount);

                // Do not depreciate past the depreciable base on the final entry
                if ($amount > $remaining) {
                    $amount = round($remaining, 2);
                }

                if ($amount <= 0) {
                    continue;
                }

                $cumulative = round($cumulative + $amount, 2);
                $bookValue = round((float) $asset->purchase_cost - $cumulative, 2);

                // Check-then-create under an asset row lock (issue #64);
                // the unique (asset_id, period_date) index backstops any
                // race that slips through between concurrent requests.
                $schedule = DB::transaction(function () use ($asset, $periodDate, $amount, $cumulative, $bookValue): ?DepreciationSchedule {
                    FixedAsset::where('id', $asset->id)->lockForUpdate()->first();

                    if (DepreciationSchedule::where('asset_id', $asset->id)
                        ->where('period_date', $periodDate->format('Y-m-d'))
                        ->exists()) {
                        return null;
                    }

                    return DepreciationSchedule::create([
                        'asset_id' => $asset->id,
                        'period_date' => $periodDate->format('Y-m-d'),
                        'depreciation_amount' => $amount,
                        'accumulated_depreciation' => $cumulative,
                        'book_value' => $bookValue,
                        'is_posted' => false,
                    ]);
                });

                if ($schedule === null) {
                    $results['skipped_existing']++;

                    continue;
                }

                // Posting the JE can legitimately fail when no fiscal period is
                // open for the date (e.g. a 3-year asset projected into 2028).
                // In that case keep the schedule row unposted so a later
                // manual run (or a fiscal-period backfill) can post it.
                try {
                    $journalEntry = $this->createDepreciationDraftJournalEntry($asset, $schedule, $periodDate);
                    $schedule->update([
                        'journal_entry_id' => $journalEntry->id,
                        'is_posted' => true,
                    ]);
                    $results['generated']++;
                } catch (\Throwable $je) {
                    $results['pending_posting'] = ($results['pending_posting'] ?? 0) + 1;
                    $results['errors'][] = [
                        'period' => $periodDate->format('Y-m-d'),
                        'message' => $je->getMessage(),
                        'schedule_id' => $schedule->id,
                        'retry_endpoint' => "/api/v1/assets/{$asset->id}/post-schedule",
                    ];
                }
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    $results['skipped_existing']++;
                } else {
                    $results['errors'][] = [
                        'period' => $periodDate->format('Y-m-d'),
                        'message' => $e->getMessage(),
                    ];
                }
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'period' => $periodDate->format('Y-m-d'),
                    'message' => $e->getMessage(),
                ];
            }
        }

        // Final-period true-up for mid-month starts: the prorated first period
        // leaves a remainder that the whole-month periods never absorb, so the
        // asset would never fully depreciate. Charge the remainder in one final
        // period (standard fixed-asset practice).
        $scheduledTotal = (float) DepreciationSchedule::where('asset_id', $asset->id)->sum('depreciation_amount');
        $trueUp = round((float) $asset->purchase_cost - (float) $asset->salvage_value - $scheduledTotal, 2);

        if ($trueUp > 0.01) {
            $trueUpDate = $this->advancePeriod($startDate, $frequency, $periodsTotal);
            $schedule = DB::transaction(function () use ($asset, $trueUpDate, $trueUp, $scheduledTotal): DepreciationSchedule {
                FixedAsset::where('id', $asset->id)->lockForUpdate()->first();

                return DepreciationSchedule::create([
                    'asset_id' => $asset->id,
                    'period_date' => $trueUpDate->format('Y-m-d'),
                    'depreciation_amount' => $trueUp,
                    'accumulated_depreciation' => round($scheduledTotal + $trueUp, 2),
                    'book_value' => max((float) $asset->purchase_cost - $scheduledTotal - $trueUp, (float) $asset->salvage_value),
                    'is_posted' => false,
                ]);
            });

            try {
                $journalEntry = $this->createDepreciationDraftJournalEntry($asset, $schedule, $trueUpDate);
                $schedule->update([
                    'journal_entry_id' => $journalEntry->id,
                    'is_posted' => true,
                ]);
                $results['generated']++;
            } catch (\Throwable $je) {
                $results['pending_posting'] = ($results['pending_posting'] ?? 0) + 1;
                $results['errors'][] = [
                    'period' => $trueUpDate->format('Y-m-d'),
                    'message' => $je->getMessage(),
                    'schedule_id' => $schedule->id,
                    'retry_endpoint' => "/api/v1/assets/{$asset->id}/post-schedule",
                ];
            }
        }

        // Issue #216: persist POSTED-only accumulated depreciation.
        // calculateDepreciationForPeriod() treats asset.accumulated_depreciation
        // as the posted baseline and adds SUM(is_posted = false) drafts on top,
        // so persisting the all-rows running total here made the two entry
        // points double-count unposted periods.
        $postedAccumulated = (float) DepreciationSchedule::where('asset_id', $asset->id)
            ->where('is_posted', true)
            ->sum('depreciation_amount');

        $asset->update([
            'accumulated_depreciation' => $postedAccumulated,
            'book_value' => max(round((float) $asset->purchase_cost - $postedAccumulated, 2), (float) $asset->salvage_value),
            'last_depreciation_date' => optional($this->advancePeriod($startDate, $frequency, $results['generated'] - 1))->format('Y-m-d'),
        ]);

        return $results;
    }

    /**
     * Post all DepreciationSchedule rows that have no journal entry yet
     * for a given asset. Typically called after generateScheduleForAsset
     * when fiscal periods are later opened for those dates.
     *
     * @return array{posted:int, errors:array, skipped_no_entry:int}
     */
    public function postPendingScheduleEntries(int $assetId): array
    {
        $asset = FixedAsset::findOrFail($assetId);

        $pending = DepreciationSchedule::where('asset_id', $asset->id)
            ->whereNull('journal_entry_id')
            ->orderBy('period_date')
            ->get();

        $result = [
            'posted' => 0,
            'errors' => [],
            'skipped_no_entry' => 0,
        ];

        foreach ($pending as $schedule) {
            try {
                // Post under a row lock and re-check journal_entry_id inside
                // the transaction so two concurrent post-pending calls (or a
                // retry after a slow response) cannot post the same schedule
                // twice (issue #64).
                $journalEntryId = DB::transaction(function () use ($asset, $schedule): ?int {
                    $locked = DepreciationSchedule::where('id', $schedule->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $locked || $locked->journal_entry_id !== null) {
                        return null;
                    }

                    $journalEntry = $this->createDepreciationDraftJournalEntry(
                        $asset,
                        $locked,
                        Carbon::parse($locked->period_date)
                    );

                    $locked->update([
                        'journal_entry_id' => $journalEntry->id,
                        'is_posted' => true,
                    ]);

                    return $journalEntry->id;
                });

                if ($journalEntryId === null) {
                    continue;
                }

                $result['posted']++;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'schedule_id' => $schedule->id,
                    'period' => $schedule->period_date,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * PostgreSQL unique-violation (SQLSTATE 23505) detection for the
     * (asset_id, period_date) backstop added in issue #64.
     */
    protected function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains(strtolower($e->getMessage() ?? ''), 'unique constraint')
            || str_contains(strtolower($e->getMessage() ?? ''), 'duplicate key');
    }

    /**
     * Compute the number of schedule periods an asset is expected to
     * produce over its useful life given its depreciation frequency.
     */
    protected function expectedPeriodCount(FixedAsset $asset): int
    {
        $years = (float) $asset->useful_life_years;
        $frequency = $asset->depreciation_frequency ?? 'monthly';

        switch ($frequency) {
            case 'annually':
            case 'yearly':
                return (int) ceil($years);
            case 'weekly':
                return (int) ceil($years * 52);
            case 'daily':
                return (int) ceil($years * 365);
            case 'monthly':
            default:
                return (int) ceil($years * 12);
        }
    }

    /**
     * Advance a starting Carbon date by N periods of the given frequency.
     */
    protected function advancePeriod(Carbon $start, string $frequency, int $periodIndex): Carbon
    {
        $date = $start->copy();

        switch ($frequency) {
            case 'annually':
            case 'yearly':
                return $date->addYears($periodIndex);
            case 'weekly':
                return $date->addWeeks($periodIndex);
            case 'daily':
                return $date->addDays($periodIndex);
            case 'monthly':
            default:
                return $date->addMonths($periodIndex);
        }
    }

    /**
     * Calculate depreciation amount based on method.
     *
     * Issue #162: declining-balance depends on the book value at the START of
     * the period, so the caller must thread its running accumulated
     * depreciation through — reading the static $asset attribute would compute
     * every period off the same starting book value.
     *
     * @param  float  $runningAccumulatedDepreciation  Accumulated depreciation at the start of this period (incl. any rows already generated).
     */
    protected function calculateDepreciationAmount(FixedAsset $asset, Carbon $periodDate, float $runningAccumulatedDepreciation): float
    {
        switch ($asset->depreciation_method) {
            case 'straight_line':
                return $this->calculateStraightLine($asset);

            case 'declining_balance':
                return $this->calculateDecliningBalance($asset, $runningAccumulatedDepreciation);

            case 'units_of_production':
                return $this->calculateUnitsOfProduction($asset);

            default:
                throw new \Exception("Unsupported depreciation method: {$asset->depreciation_method}");
        }
    }

    /**
     * Prorate the FIRST schedule period when the asset's depreciation starts
     * mid-month. The client's schedule charges the acquisition month by active
     * days on a 30-day month basis (e.g. acquired 21 May → 11/30 of the monthly
     * charge; acquired 30 Sep → 1/30). Assets starting on the 1st keep the
     * full monthly charge, so straight-line assets whose life is expressed in
     * whole months are unchanged.
     */
    protected function prorateFirstPeriodAmount(FixedAsset $asset, Carbon $periodDate, float $amount): float
    {
        $start = $asset->depreciation_start_date
            ? Carbon::parse($asset->depreciation_start_date)
            : null;

        if (! $start || $start->day === 1 || ! $start->isSameMonth($periodDate)) {
            return $amount;
        }

        $daysActive = $start->daysInMonth - $start->day + 1;

        return round($amount * $daysActive / 30, 2);
    }

    /**
     * Straight-line depreciation
     * (Cost - Salvage Value) / Useful Life / 12 months
     */
    protected function calculateStraightLine(FixedAsset $asset): float
    {
        $depreciableAmount = $asset->purchase_cost - $asset->salvage_value;
        $monthlyDepreciation = $depreciableAmount / ($asset->useful_life_years * 12);

        return round($monthlyDepreciation, 2);
    }

    /**
     * Declining balance depreciation (200% declining balance)
     * Book Value * (2 / Useful Life) / 12 months
     *
     * Issue #162: book value must be computed from the running accumulated
     * depreciation for this period, not the stale $asset attribute.
     */
    protected function calculateDecliningBalance(FixedAsset $asset, float $runningAccumulatedDepreciation): float
    {
        $bookValue = (float) $asset->purchase_cost - $runningAccumulatedDepreciation;
        $rate = 2.0 / $asset->useful_life_years;
        $monthlyDepreciation = $bookValue * $rate / 12;

        return round($monthlyDepreciation, 2);
    }

    /**
     * Units of production depreciation
     * (Cost - Salvage Value) * (Units Used / Total Units)
     * Note: This is a simplified version. In practice, you'd track actual units used.
     */
    protected function calculateUnitsOfProduction(FixedAsset $asset): float
    {
        // For now, assume equal usage per month
        $depreciableAmount = $asset->purchase_cost - $asset->salvage_value;
        $monthlyUnits = $asset->useful_life_units / ($asset->useful_life_years * 12);
        $costPerUnit = $depreciableAmount / $asset->useful_life_units;
        $monthlyDepreciation = $monthlyUnits * $costPerUnit;

        return round($monthlyDepreciation, 2);
    }

    /**
     * Create journal entry for depreciation
     * DR: Depreciation Expense
     * CR: Accumulated Depreciation
     */
    protected function createDepreciationDraftJournalEntry(
        FixedAsset $asset,
        DepreciationSchedule $schedule,
        Carbon $periodDate
    ): JournalEntry {
        $lines = [
            new PostingRequestLine(
                accountMappingKey: 'depreciation_expense',
                accountId: $asset->depreciation_account_id,
                debit: (float) $schedule->depreciation_amount,
                credit: 0,
                description: "Depreciation - {$asset->name}",
            ),
            new PostingRequestLine(
                accountMappingKey: 'accumulated_depreciation',
                accountId: $asset->accumulated_depreciation_account_id,
                debit: 0,
                credit: (float) $schedule->depreciation_amount,
                description: "Accumulated Depreciation - {$asset->name}",
            ),
        ];

        $result = $this->postingEngine->createDraft(DraftJournalRequest::fromArray([
            'entry_date' => $periodDate->format('Y-m-d'),
            'description' => "Depreciation for {$asset->name} - ".$periodDate->format('M Y'),
            'reference' => $asset->asset_number,
            'lines' => array_map(fn (PostingRequestLine $line) => [
                'account_id' => $line->accountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
            ], $lines),
            'user_id' => TenantUserHelper::resolveTenantUserId(),
        ]));

        if (! $result->success) {
            throw new \Exception($result->error);
        }

        return $result->journalEntry;
    }

    /**
     * Dispose asset and create disposal journal entry
     */
    public function disposeAsset(
        FixedAsset $asset,
        string $disposalDate,
        float $disposalAmount,
        string $disposalMethod,
        ?string $notes = null
    ): array {
        $disposalDate = Carbon::parse($disposalDate);

        // Calculate gain/loss
        $bookValue = $asset->calculateBookValue();
        $gainLoss = $disposalAmount - $bookValue;

        // Build posting lines via PostingEngine
        $lines = [];

        // DR: Cash/Bank (disposal amount)
        if ($disposalAmount > 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'cash',
                accountId: null,
                debit: $disposalAmount,
                credit: 0,
                description: "Proceeds from disposal of {$asset->name}",
            );
        }

        // DR: Accumulated Depreciation (remove)
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'accumulated_depreciation',
            accountId: $asset->accumulated_depreciation_account_id,
            debit: (float) $asset->accumulated_depreciation,
            credit: 0,
            description: "Remove accumulated depreciation - {$asset->name}",
        );

        // Gain/Loss
        if ($gainLoss > 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'gain_loss_disposal',
                accountId: null,
                debit: 0,
                credit: abs($gainLoss),
                description: "Gain on disposal of {$asset->name}",
            );
        } elseif ($gainLoss < 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'gain_loss_disposal',
                accountId: null,
                debit: abs($gainLoss),
                credit: 0,
                description: "Loss on disposal of {$asset->name}",
            );
        }

        // CR: Asset Account (original cost)
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'inventory', // fallback key, overridden by accountId
            accountId: $asset->asset_account_id,
            debit: 0,
            credit: (float) $asset->purchase_cost,
            description: "Remove asset - {$asset->name}",
        );

        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'asset_disposal',
            documentId: $asset->id,
            postingDate: $disposalDate->format('Y-m-d'),
            description: "Disposal of {$asset->name} - {$disposalMethod}".($notes ? " - {$notes}" : ''),
            lines: $lines,
            reference: $asset->asset_number,
            userId: TenantUserHelper::resolveTenantUserId(),
        ));

        if (! $result->success) {
            throw new \Exception($result->error);
        }

        // Update asset
        $asset->update([
            'status' => 'disposed',
            'disposal_date' => $disposalDate->format('Y-m-d'),
            'disposal_amount' => $disposalAmount,
            'disposal_method' => $disposalMethod,
            'is_active' => false,
        ]);

        return [
            'gain_loss' => $gainLoss,
            'journal_entry' => $result->journalEntry,
        ];
    }
}
