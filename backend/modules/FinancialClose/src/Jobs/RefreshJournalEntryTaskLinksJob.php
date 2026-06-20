<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseTask;

/**
 * Dispatched when a journal entry status changes to refresh the related
 * unposted_journal_entries task's document_links.
 */
class RefreshJournalEntryTaskLinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $cycleId,
        public readonly int $journalEntryId,
    ) {}

    public function handle(): void
    {
        tenancy()->initialize($this->tenantId);

        $JE = DB::table('journal_entries')
            ->where('id', $this->journalEntryId)
            ->first();

        if (!$JE) {
            Log::warning('RefreshJournalEntryTaskLinksJob: JE not found', [
                'journal_entry_id' => $this->journalEntryId,
            ]);
            return;
        }

        $taskType = 'unposted_journal_entries';

        $task = CloseTask::where('tenant_id', $this->tenantId)
            ->where('cycle_id', $this->cycleId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->get()
            ->keyBy(fn ($t) => $t->metadata['type'] ?? null)
            ->get($taskType);

        if (!$task) {
            Log::info('RefreshJournalEntryTaskLinksJob: No active unposted_journal_entries task found', [
                'cycle_id' => $this->cycleId,
            ]);
            return;
        }

        $currentLinks = $task->metadata['document_links'] ?? [];

        if ($JE->status === 'posted' || $JE->status === 'draft') {
            $updatedLinks = array_values(array_filter($currentLinks, function ($link) {
                return $link['type'] !== 'journal_entry' || (int) $link['id'] !== $this->journalEntryId;
            }));

            $draftCount = DB::table('journal_entries')
                ->where('status', 'draft')
                ->count();

            $task->update([
                'metadata' => array_merge($task->metadata ?? [], [
                    'document_links' => $updatedLinks,
                    'count' => $draftCount,
                ]),
                'title' => "Review and post {$draftCount} draft journal entries",
            ]);

            Log::info('RefreshJournalEntryTaskLinksJob: Task links refreshed', [
                'task_id' => $task->id,
                'JE_id' => $this->journalEntryId,
                'JE_status' => $JE->status,
                'remaining_links' => count($updatedLinks),
            ]);
        }
    }
}