<?php

namespace App\Observers;

use App\Models\JournalEntry;
use App\Models\Tenant;
use Modules\FinancialClose\Jobs\RefreshJournalEntryTaskLinksJob;
use Modules\OCRBookkeeping\Jobs\UpdateDailyLedgerSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Journal Entry Observer
 * 
 * Listens to journal entry events and triggers BI updates
 * and Financial Close task refresh when status changes.
 */
class JournalEntryObserver
{
    /**
     * Handle the JournalEntry "created" event.
     */
    public function created(JournalEntry $journalEntry): void
    {
        if ($journalEntry->status === 'posted') {
            $this->dispatchBIUpdate($journalEntry);
            $this->dispatchTaskRefresh($journalEntry);
        }
    }

    /**
     * Handle the JournalEntry "updated" event.
     */
    public function updated(JournalEntry $journalEntry): void
    {
        if ($journalEntry->wasChanged('status') && $journalEntry->status === 'posted') {
            $this->dispatchBIUpdate($journalEntry);
            $this->dispatchTaskRefresh($journalEntry);
        }
    }

    /**
     * Dispatch task refresh job to update Financial Close task links
     */
    private function dispatchTaskRefresh(JournalEntry $journalEntry): void
    {
        try {
            $tenantId = $this->resolveTenantId($journalEntry);
            if (!$tenantId) {
                return;
            }

            $activeCycles = DB::table('fco_close_cycles')
                ->where('tenant_id', $tenantId)
                ->where('status', 'opened')
                ->pluck('id');

            foreach ($activeCycles as $cycleId) {
                RefreshJournalEntryTaskLinksJob::dispatch(
                    tenantId: $tenantId,
                    cycleId: $cycleId,
                    journalEntryId: $journalEntry->id,
                )->delay(now()->addSeconds(5));
            }

            Log::info('JournalEntryObserver: Task refresh job dispatched', [
                'journal_entry_id' => $journalEntry->id,
                'status' => $journalEntry->status,
                'tenant_id' => $tenantId,
                'cycles_count' => $activeCycles->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('JournalEntryObserver: Failed to dispatch task refresh', [
                'journal_entry_id' => $journalEntry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve tenant ID from journal entry
     */
    private function resolveTenantId(JournalEntry $journalEntry): ?string
    {
        if (function_exists('tenant') && tenant()) {
            return (string) tenant()->getTenantKey();
        }

        if ($journalEntry->tenant_id ?? null) {
            return (string) $journalEntry->tenant_id;
        }

        if ($journalEntry->tenant?->id) {
            return (string) $journalEntry->tenant->id;
        }

        if (tenancy()->initialized) {
            return (string) tenant()->id;
        }

        return null;
    }

    /**
     * Dispatch BI update job
     */
    private function dispatchBIUpdate(JournalEntry $journalEntry): void
    {
        try {
            $date = Carbon::parse($journalEntry->entry_date);
            $tenantId = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : null;

            if (!$tenantId && $journalEntry->tenant_id ?? null) {
                $tenantId = (string) $journalEntry->tenant_id;
            }

            if (!$tenantId && $journalEntry->tenant?->id) {
                $tenantId = (string) $journalEntry->tenant->id;
            }

            // Use tenancy context directly instead of parsing search_path
            if (!$tenantId && tenancy()->initialized) {
                $tenantId = (string) tenant()->id;
            }

            if (!$tenantId) {
                throw new \RuntimeException('Unable to resolve tenant context for daily ledger summary dispatch.');
            }
            
            UpdateDailyLedgerSummary::dispatch($date, $tenantId)
                ->onQueue('bi-updates')
                ->delay(now()->addSeconds(30)); // Delay to batch multiple updates
            
            Log::info("BI update job dispatched", [
                'journal_entry_id' => $journalEntry->id,
                'entry_date' => $date->format('Y-m-d'),
                'tenant_id' => $tenantId,
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to dispatch BI update job", [
                'journal_entry_id' => $journalEntry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
