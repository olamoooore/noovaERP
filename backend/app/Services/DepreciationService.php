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

                $period = $start->copy();
                $scheduleEnd = $start->copy()->addMonths($months - 1)->endOfMonth();
                $created = 0;
                while ($period->lte($scheduleEnd)) {
                    $periodEnd = $period->copy()->endOfMonth();
                    $existing = DepreciationSchedule::where('asset_id', $asset->id)
                        ->whereDate('period_date', $periodEnd->toDateString())
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

                        $amount = min($this->calculateDepreciationAmount($asset, $period), $remaining);
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
                        try {
                            $journalEntry = $this->createDepreciationDraftJournalEntry($asset, $schedule, $periodEnd);
                        } catch (\Throwable $e) {
                            // Do not leave an orphaned schedule when draft creation fails.
                            $schedule->delete();
                            throw $e;
                        }
                        $schedule->update(['journal_entry_id' => $journalEntry->id]);
                        $created++;
                    }
                    $period->addMonth();
                }

                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => $created > 0 ? 'success' : 'skipped',
                    'message' => $created > 0 ? "Created {$created} draft depreciation journal(s)." : 'No missing depreciation periods.',
                    'draft_count' => $created,
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
     * Calculate depreciation amount based on method
     */
    protected function calculateDepreciationAmount(FixedAsset $asset, Carbon $periodDate): float
    {
        switch ($asset->depreciation_method) {
            case 'straight_line':
                return $this->calculateStraightLine($asset);

            case 'declining_balance':
                return $this->calculateDecliningBalance($asset);

            case 'units_of_production':
                return $this->calculateUnitsOfProduction($asset);

            default:
                throw new \Exception("Unsupported depreciation method: {$asset->depreciation_method}");
        }
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
     */
    protected function calculateDecliningBalance(FixedAsset $asset): float
    {
        $bookValue = $asset->calculateBookValue();
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
