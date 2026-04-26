<?php

namespace App\Observers;

use App\Models\JournalEntry;
use App\Models\Tenant;
use Modules\OCRBookkeeping\Jobs\UpdateDailyLedgerSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Journal Entry Observer
 * 
 * Listens to journal entry events and triggers BI updates
 */
class JournalEntryObserver
{
    /**
     * Handle the JournalEntry "created" event.
     */
    public function created(JournalEntry $journalEntry): void
    {
        // Dispatch BI update when entry is created and posted
        if ($journalEntry->status === 'posted') {
            $this->dispatchBIUpdate($journalEntry);
        }
    }

    /**
     * Handle the JournalEntry "updated" event.
     */
    public function updated(JournalEntry $journalEntry): void
    {
        // Dispatch BI update when entry status changes to posted
        if ($journalEntry->wasChanged('status') && $journalEntry->status === 'posted') {
            $this->dispatchBIUpdate($journalEntry);
        }
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
