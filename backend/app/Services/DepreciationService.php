<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\DepreciationSchedule;
use App\Models\JournalEntry;
use App\Models\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Depreciation Service
 * Handles depreciation calculations and journal entry generation
 */
class DepreciationService
{
    protected $accountingService;

    public function __construct(AccountingService $accountingService)
    {
        $this->accountingService = $accountingService;
    }

    /**
     * Calculate depreciation for a period
     * @param string $periodDate - Date in format Y-m-d (e.g., '2024-01-31')
     * @param array|null $assetIds - Specific asset IDs or null for all active assets
     */
    public function calculateDepreciationForPeriod(string $periodDate, ?array $assetIds = null): array
    {
        $periodDate = Carbon::parse($periodDate);
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
            // Check if already depreciated for this period
            $existing = DepreciationSchedule::where('asset_id', $asset->id)
                ->where('period_date', $periodDate->format('Y-m-d'))
                ->first();

            if ($existing) {
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => 'skipped',
                    'message' => 'Already depreciated for this period'
                ];
                continue;
            }

            // Check if fully depreciated
            if ($asset->isFullyDepreciated()) {
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => 'skipped',
                    'message' => 'Asset fully depreciated'
                ];
                continue;
            }

            try {
                $depreciationAmount = $this->calculateDepreciationAmount($asset, $periodDate);

                // Ensure we don't depreciate below salvage value
                $currentBookValue = $asset->calculateBookValue();
                $newBookValue = $currentBookValue - $depreciationAmount;

                if ($newBookValue < $asset->salvage_value) {
                    $depreciationAmount = $currentBookValue - $asset->salvage_value;
                    $newBookValue = $asset->salvage_value;
                }

                if ($depreciationAmount <= 0) {
                    $results[] = [
                        'asset_id' => $asset->id,
                        'asset_name' => $asset->name,
                        'status' => 'skipped',
                        'message' => 'No depreciation needed'
                    ];
                    continue;
                }

                // Create depreciation schedule entry
                $schedule = DepreciationSchedule::create([
                    'asset_id' => $asset->id,
                    'period_date' => $periodDate->format('Y-m-d'),
                    'depreciation_amount' => $depreciationAmount,
                    'accumulated_depreciation' => $asset->accumulated_depreciation + $depreciationAmount,
                    'book_value' => $newBookValue,
                    'is_posted' => false,
                ]);

                // Create journal entry
                $journalEntry = $this->createDepreciationJournalEntry($asset, $schedule, $periodDate);

                // Update schedule with journal entry ID
                $schedule->update([
                    'journal_entry_id' => $journalEntry->id,
                    'is_posted' => true,
                ]);

                // Update asset
                $asset->update([
                    'accumulated_depreciation' => $asset->accumulated_depreciation + $depreciationAmount,
                    'book_value' => $newBookValue,
                    'last_depreciation_date' => $periodDate->format('Y-m-d'),
                ]);

                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => 'success',
                    'depreciation_amount' => $depreciationAmount,
                    'accumulated_depreciation' => $asset->accumulated_depreciation,
                    'book_value' => $newBookValue,
                    'journal_entry_id' => $journalEntry->id,
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => 'error',
                    'message' => $e->getMessage()
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
    protected function createDepreciationJournalEntry(
        FixedAsset $asset,
        DepreciationSchedule $schedule,
        Carbon $periodDate
    ): JournalEntry {
        $lines = [
            [
                'account_id' => $asset->depreciation_account_id,
                'debit' => $schedule->depreciation_amount,
                'credit' => 0,
                'description' => "Depreciation - {$asset->name}",
            ],
            [
                'account_id' => $asset->accumulated_depreciation_account_id,
                'debit' => 0,
                'credit' => $schedule->depreciation_amount,
                'description' => "Accumulated Depreciation - {$asset->name}",
            ],
        ];

        return $this->accountingService->createJournalEntry([
            'entry_date' => $periodDate->format('Y-m-d'),
            'type' => 'depreciation',
            'reference' => $asset->asset_number,
            'description' => "Depreciation for {$asset->name} - " . $periodDate->format('M Y'),
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ], $lines);
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

        // Create disposal journal entry
        // DR: Cash/Bank (disposal amount)
        // DR: Accumulated Depreciation (accumulated depreciation)
        // DR/CR: Gain/Loss on Disposal (difference)
        // CR: Asset Account (original cost)

        $cashAccount = ChartOfAccount::where('code', '1010')->first(); // Cash
        $gainLossAccount = ChartOfAccount::where('code', '7100')->first(); // Other Income/Expense

        $lines = [
            [
                'account_id' => $cashAccount->id,
                'debit' => $disposalAmount,
                'credit' => 0,
                'description' => "Proceeds from disposal of {$asset->name}",
            ],
            [
                'account_id' => $asset->accumulated_depreciation_account_id,
                'debit' => $asset->accumulated_depreciation,
                'credit' => 0,
                'description' => "Remove accumulated depreciation - {$asset->name}",
            ],
        ];

        // Add gain or loss line
        if ($gainLoss > 0) {
            // Gain - credit
            $lines[] = [
                'account_id' => $gainLossAccount->id,
                'debit' => 0,
                'credit' => abs($gainLoss),
                'description' => "Gain on disposal of {$asset->name}",
            ];
        } else if ($gainLoss < 0) {
            // Loss - debit
            $lines[] = [
                'account_id' => $gainLossAccount->id,
                'debit' => abs($gainLoss),
                'credit' => 0,
                'description' => "Loss on disposal of {$asset->name}",
            ];
        }

        // Credit asset account
        $lines[] = [
            'account_id' => $asset->asset_account_id,
            'debit' => 0,
            'credit' => $asset->purchase_cost,
            'description' => "Remove asset - {$asset->name}",
        ];

        $journalEntry = $this->accountingService->createJournalEntry([
            'entry_date' => $disposalDate->format('Y-m-d'),
            'type' => 'asset_disposal',
            'reference' => $asset->asset_number,
            'description' => "Disposal of {$asset->name} - {$disposalMethod}" . ($notes ? " - {$notes}" : ''),
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ], $lines);

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
            'journal_entry' => $journalEntry,
        ];
    }
}
