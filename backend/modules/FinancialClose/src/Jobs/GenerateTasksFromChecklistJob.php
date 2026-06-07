<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Services\FinancialCloseChecklistService;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\JobRunHistory;

/**
 * Dispatched when a cycle opens — expands the active checklist template into tasks.
 * If no template_id on cycle, generates conditional tasks based on tenant data:
 *   - Unposted journal entries
 *   - Unpaid vendor bills
 *   - Overdue items
 */
class GenerateTasksFromChecklistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $cycleId,
        public readonly string $correlationId = '',
    ) {}

    public function handle(FinancialCloseChecklistService $checklistService): void
    {
        $startedAt = now();

        $cycle = CloseCycle::where('tenant_id', $this->tenantId)
            ->where('id', $this->cycleId)
            ->first();

        if (!$cycle) {
            Log::warning('FinancialClose: GenerateTasksFromChecklistJob — cycle not found', [
                'cycle_id' => $this->cycleId,
            ]);
            return;
        }

        $templateId = $cycle->template_id ?? null;

        if ($templateId) {
            $result = $checklistService->expandTemplateIntoTasks($this->tenantId, $this->cycleId, $templateId);

            Log::info('FinancialClose: GenerateTasksFromChecklistJob complete (template)', [
                'tenant_id'      => $this->tenantId,
                'cycle_id'       => $this->cycleId,
                'template_id'    => $templateId,
                'result'         => $result,
                'correlation_id' => $this->correlationId,
            ]);

            JobRunHistory::create([
                'tenant_id'      => $this->tenantId,
                'job_type'       => 'GenerateTasksFromChecklistJob',
                'status'         => $result ? 'success' : 'failed',
                'started_at'     => $startedAt,
                'completed_at'   => now(),
                'duration_ms'    => (int) ($startedAt->diffInMilliseconds(now())),
                'error_message'  => $result ? null : 'Task expansion returned false',
                'metadata'       => [
                    'cycle_id'       => $this->cycleId,
                    'template_id'    => $templateId,
                    'result'         => $result,
                    'correlation_id' => $this->correlationId,
                ],
            ]);
        } else {
            $generated = $this->generateConditionalTasks($this->tenantId, $this->cycleId);

            Log::info('FinancialClose: GenerateTasksFromChecklistJob complete (conditional)', [
                'tenant_id'      => $this->tenantId,
                'cycle_id'       => $this->cycleId,
                'tasks_created'  => $generated,
                'correlation_id' => $this->correlationId,
            ]);

            JobRunHistory::create([
                'tenant_id'      => $this->tenantId,
                'job_type'       => 'GenerateTasksFromChecklistJob',
                'status'         => 'success',
                'started_at'     => $startedAt,
                'completed_at'   => now(),
                'duration_ms'    => (int) ($startedAt->diffInMilliseconds(now())),
                'metadata'       => [
                    'cycle_id'       => $this->cycleId,
                    'tasks_created'  => $generated,
                    'correlation_id' => $this->correlationId,
                ],
            ]);
        }
    }

    /**
     * Generate tasks based on actual tenant data conditions.
     */
    private function generateConditionalTasks(string $tenantId, string $cycleId): int
    {
        $count = 0;

        // 1. Unposted journal entries
        $unpostedJE = DB::table('journal_entries')
            ->where('status', 'draft')
            ->count();
        if ($unpostedJE > 0) {
            CloseTask::create([
                'tenant_id'   => $tenantId,
                'cycle_id'    => $cycleId,
                'title'       => "Review and post {$unpostedJE} draft journal entries",
                'status'      => 'created',
                'priority'    => 'high',
                'is_critical' => true,
                'metadata'    => ['type' => 'unposted_journal_entries', 'count' => $unpostedJE],
            ]);
            $count++;
        }

        // 2. Unpaid vendor bills (posted or partial)
        $unpaidBills = DB::table('bills')
            ->whereIn('status', ['posted', 'partial'])
            ->count();
        if ($unpaidBills > 0) {
            CloseTask::create([
                'tenant_id'   => $tenantId,
                'cycle_id'    => $cycleId,
                'title'       => "Process {$unpaidBills} unpaid vendor bills",
                'status'      => 'created',
                'priority'    => 'high',
                'is_critical' => true,
                'metadata'    => ['type' => 'unpaid_bills', 'count' => $unpaidBills],
            ]);
            $count++;
        }

        // 3. Overdue invoices (customer invoices past due date)
        $overdueInvoices = DB::table('invoices')
            ->whereIn('status', ['sent', 'partial'])
            ->where('due_date', '<', now()->toDateString())
            ->count();
        if ($overdueInvoices > 0) {
            CloseTask::create([
                'tenant_id'   => $tenantId,
                'cycle_id'    => $cycleId,
                'title'       => "Follow up on {$overdueInvoices} overdue customer invoices",
                'status'      => 'created',
                'priority'    => 'critical',
                'is_critical' => true,
                'metadata'    => ['type' => 'overdue_invoices', 'count' => $overdueInvoices],
            ]);
            $count++;
        }

        // 4. Unreconciled bank transactions
        $bankAccountIds = DB::table('chart_of_accounts')
            ->whereIn('subtype', ['asset_cash', 'asset_current'])
            ->where('type', 'asset')
            ->pluck('id');

        $unreconciled = 0;
        if ($bankAccountIds->isNotEmpty()) {
            $unreconciled = DB::table('journal_entry_lines')
                ->whereNull('reconciliation_id')
                ->whereIn('account_id', $bankAccountIds)
                ->count();
        }
        if ($unreconciled > 0) {
            CloseTask::create([
                'tenant_id'   => $tenantId,
                'cycle_id'    => $cycleId,
                'title'       => "Reconcile {$unreconciled} unreconciled bank transactions",
                'status'      => 'created',
                'priority'    => 'high',
                'is_critical' => false,
                'metadata'    => ['type' => 'unreconciled_transactions', 'count' => $unreconciled],
            ]);
            $count++;
        }

        // 5. Default close tasks (always created)
        $defaultTasks = [
            ['title' => 'Review trial balance', 'priority' => 'high', 'is_critical' => true],
            ['title' => 'Run depreciation entries', 'priority' => 'medium', 'is_critical' => false],
            ['title' => 'Review accruals and prepayments', 'priority' => 'medium', 'is_critical' => false],
            ['title' => 'Generate financial statements', 'priority' => 'high', 'is_critical' => true],
            ['title' => 'Final review and sign-off', 'priority' => 'critical', 'is_critical' => true],
        ];

        foreach ($defaultTasks as $task) {
            CloseTask::create([
                'tenant_id'   => $tenantId,
                'cycle_id'    => $cycleId,
                'title'       => $task['title'],
                'status'      => 'created',
                'priority'    => $task['priority'],
                'is_critical' => $task['is_critical'],
            ]);
            $count++;
        }

        return $count;
    }
}
