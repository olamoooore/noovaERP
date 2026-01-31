<?php

namespace Modules\OCRBookkeeping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Update Daily Ledger Summary Job
 * 
 * Calculates and stores daily ledger summaries for BI/reporting
 * Runs after journal entries are posted
 */
class UpdateDailyLedgerSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The date to calculate summary for
     */
    private Carbon $date;

    /**
     * Create a new job instance.
     */
    public function __construct(Carbon $date)
    {
        $this->date = $date;
        $this->queue = 'bi-updates';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Updating daily ledger summary", ['date' => $this->date->format('Y-m-d')]);

            // Calculate summaries by account type
            $this->calculateAccountTypeSummaries();

            // Calculate summaries by dimensions
            $this->calculateDimensionSummaries();

            Log::info("Daily ledger summary updated successfully", ['date' => $this->date->format('Y-m-d')]);

        } catch (\Exception $e) {
            Log::error("Failed to update daily ledger summary", [
                'date' => $this->date->format('Y-m-d'),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Calculate summaries by account type
     */
    private function calculateAccountTypeSummaries(): void
    {
        $summaries = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.entry_date', $this->date->format('Y-m-d'))
            ->where('journal_entries.status', 'posted')
            ->select(
                'journal_entry_lines.account_id',
                'chart_of_accounts.type as account_type',
                'chart_of_accounts.subtype as account_category',
                DB::raw('SUM(journal_entry_lines.debit) as total_debit'),
                DB::raw('SUM(journal_entry_lines.credit) as total_credit'),
                DB::raw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as net_amount'),
                DB::raw('COUNT(DISTINCT journal_entries.id) as transaction_count')
            )
            ->groupBy('journal_entry_lines.account_id', 'chart_of_accounts.type', 'chart_of_accounts.subtype')
            ->get();

        foreach ($summaries as $summary) {
            DB::table('daily_ledger_summaries')->updateOrInsert(
                [
                    'summary_date' => $this->date->format('Y-m-d'),
                    'account_id' => $summary->account_id,
                    'dimensions' => null,
                ],
                [
                    'account_type' => $summary->account_type,
                    'account_category' => $summary->account_category,
                    'total_debit' => $summary->total_debit,
                    'total_credit' => $summary->total_credit,
                    'net_amount' => $summary->net_amount,
                    'transaction_count' => $summary->transaction_count,
                    'calculated_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Calculate summaries by dimensions
     */
    private function calculateDimensionSummaries(): void
    {
        // Get all entries with dimensions for this date
        $entries = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.entry_date', $this->date->format('Y-m-d'))
            ->where('journal_entries.status', 'posted')
            ->whereNotNull('journal_entry_lines.dimensions')
            ->select(
                'journal_entry_lines.account_id',
                'chart_of_accounts.type as account_type',
                'chart_of_accounts.subtype as account_category',
                'journal_entry_lines.dimensions',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit'
            )
            ->get();

        // Group by account and dimensions
        $dimensionGroups = [];
        
        foreach ($entries as $entry) {
            $dimensions = json_decode($entry->dimensions, true);
            
            if (!$dimensions || empty($dimensions)) {
                continue;
            }

            // Create a unique key for this account + dimensions combination
            $dimensionsJson = json_encode($dimensions);
            $key = $entry->account_id . '_' . md5($dimensionsJson);

            if (!isset($dimensionGroups[$key])) {
                $dimensionGroups[$key] = [
                    'account_id' => $entry->account_id,
                    'account_type' => $entry->account_type,
                    'account_category' => $entry->account_category,
                    'dimensions' => $dimensionsJson,
                    'total_debit' => 0,
                    'total_credit' => 0,
                    'transaction_count' => 0,
                ];
            }

            $dimensionGroups[$key]['total_debit'] += $entry->debit;
            $dimensionGroups[$key]['total_credit'] += $entry->credit;
            $dimensionGroups[$key]['transaction_count']++;
        }

        // Insert/update dimension summaries
        foreach ($dimensionGroups as $group) {
            DB::table('daily_ledger_summaries')->updateOrInsert(
                [
                    'summary_date' => $this->date->format('Y-m-d'),
                    'account_id' => $group['account_id'],
                    'dimensions' => $group['dimensions'],
                ],
                [
                    'account_type' => $group['account_type'],
                    'account_category' => $group['account_category'],
                    'total_debit' => $group['total_debit'],
                    'total_credit' => $group['total_credit'],
                    'net_amount' => $group['total_debit'] - $group['total_credit'],
                    'transaction_count' => $group['transaction_count'],
                    'calculated_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
