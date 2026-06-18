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
        public readonly ?string $userId = null,
    ) {}

    public function handle(FinancialCloseChecklistService $checklistService): void
    {
        tenancy()->initialize($this->tenantId);

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
            $result = $checklistService->expandTemplateIntoTasks($this->tenantId, $this->cycleId, $templateId, $this->userId);

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
            $generated = $this->generateConditionalTasks($this->tenantId, $this->cycleId, $this->userId);

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
     *
     * Each data-driven task stores the underlying source record IDs as
     * document_links so users can drill down from the task to the actual
     * documents (journal entries, bills, invoices, etc.).
     *
     * The generation is idempotent: tasks keyed by metadata.type are only
     * created once per close cycle.
     */
    private function generateConditionalTasks(string $tenantId, string $cycleId, ?string $userId = null): int
    {
        $count = 0;

        $allExistingTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->get()
            ->keyBy(fn ($task) => $task->metadata['type'] ?? null)
            ->filter(fn ($task, $type) => $type !== null);

        $activeExistingTasks = $allExistingTasks->filter(
            fn ($task) => !in_array($task->status, ['completed', 'cancelled'], true)
        );

        $upsertTask = function (array $data) use ($tenantId, $cycleId, $userId, $allExistingTasks, $activeExistingTasks, &$count) {
            $type = $data['metadata']['type'] ?? null;
            if (!$type) {
                return;
            }

            $existing = $allExistingTasks->get($type);

            if ($existing) {
                $metadata = $existing->metadata ?? [];
                $newCount = $data['metadata']['count'] ?? null;
                $existingLinks = $metadata['document_links'] ?? [];
                $newLinks = $data['metadata']['document_links'] ?? [];
                $linksChanged = json_encode($existingLinks) !== json_encode($newLinks);
                $needsUpdate = empty($existingLinks)
                    || ($metadata['count'] ?? null) !== $newCount
                    || $existing->title !== $data['title']
                    || $linksChanged;

                if ($needsUpdate) {
                    $updatePayload = [
                        'title'    => $data['title'],
                        'metadata' => array_merge($metadata, [
                            'document_links' => $data['metadata']['document_links'] ?? [],
                        ]),
                    ];
                    if ($newCount !== null) {
                        $updatePayload['metadata']['count'] = $newCount;
                    }
                    $existing->update($updatePayload);
                    $count++;
                }

                // Only create a new active task if no active task of this type exists.
                if ($activeExistingTasks->has($type)) {
                    return;
                }
            }

            CloseTask::create([
                'tenant_id'    => $tenantId,
                'cycle_id'     => $cycleId,
                'title'        => $data['title'],
                'status'       => 'created',
                'priority'     => $data['priority'],
                'is_critical'  => $data['is_critical'],
                'assigned_to'  => $userId,
                'metadata'     => $data['metadata'],
            ]);
            $count++;
        };

        // 1. Unposted journal entries
        $unpostedJEs = DB::table('journal_entries')
            ->where('status', 'draft')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'entry_number']);

        if ($unpostedJEs->isNotEmpty()) {
            $upsertTask([
                'title'       => "Review and post {$unpostedJEs->count()} draft journal entries",
                'priority'    => 'high',
                'is_critical' => true,
                'metadata'    => [
                    'type'          => 'unposted_journal_entries',
                    'count'         => $unpostedJEs->count(),
                    'document_links' => $unpostedJEs->map(fn ($je) => [
                        'type'  => 'journal_entry',
                        'id'    => $je->id,
                        'title' => $je->entry_number ?: "JE #{$je->id}",
                    ])->all(),
                ],
            ]);
        }

        // 2. Unpaid vendor bills (posted or partial)
        $unpaidBills = DB::table('bills')
            ->leftJoin('vendors', 'bills.vendor_id', '=', 'vendors.id')
            ->whereIn('bills.status', ['posted', 'partial'])
            ->orderBy('bills.id')
            ->limit(50)
            ->get(['bills.id', 'bills.bill_number', 'vendors.name as vendor_name']);

        if ($unpaidBills->isNotEmpty()) {
            $upsertTask([
                'title'       => "Process {$unpaidBills->count()} unpaid vendor bills",
                'priority'    => 'high',
                'is_critical' => true,
                'metadata'    => [
                    'type'          => 'unpaid_bills',
                    'count'         => $unpaidBills->count(),
                    'document_links' => $unpaidBills->map(fn ($bill) => [
                        'type'  => 'bill',
                        'id'    => $bill->id,
                        'title' => $bill->bill_number ?: ($bill->vendor_name ? "{$bill->vendor_name} Bill" : "Bill #{$bill->id}"),
                    ])->all(),
                ],
            ]);
        }

        // 3. Overdue invoices (customer invoices past due date)
        $overdueInvoices = DB::table('invoices')
            ->leftJoin('customers', 'invoices.customer_id', '=', 'customers.id')
            ->whereIn('invoices.status', ['sent', 'partial', 'posted'])
            ->where('invoices.due_date', '<', now()->toDateString())
            ->orderBy('invoices.id')
            ->limit(50)
            ->get(['invoices.id', 'invoices.invoice_number', 'customers.name as customer_name']);

        if ($overdueInvoices->isNotEmpty()) {
            $upsertTask([
                'title'       => "Follow up on {$overdueInvoices->count()} overdue customer invoices",
                'priority'    => 'critical',
                'is_critical' => true,
                'metadata'    => [
                    'type'          => 'overdue_invoices',
                    'count'         => $overdueInvoices->count(),
                    'document_links' => $overdueInvoices->map(fn ($inv) => [
                        'type'  => 'invoice',
                        'id'    => $inv->id,
                        'title' => $inv->invoice_number ?: ($inv->customer_name ? "{$inv->customer_name} Invoice" : "Invoice #{$inv->id}"),
                    ])->all(),
                ],
            ]);
        }

        // 4. Unreconciled bank transactions
        $bankAccountIds = DB::table('bank_accounts')
            ->where('is_active', true)
            ->pluck('gl_account_id');

        if ($bankAccountIds->isNotEmpty()) {
            $unreconciled = DB::table('journal_entry_lines')
                ->whereNull('reconciliation_id')
                ->whereIn('account_id', $bankAccountIds)
                ->distinct('journal_entry_id')
                ->orderBy('journal_entry_id')
                ->limit(50)
                ->pluck('journal_entry_id');

            if ($unreconciled->isNotEmpty()) {
                // Build document links: prefer payment reference, fall back to JE entry number
                $paymentMap = DB::table('payments')
                    ->whereIn('journal_entry_id', $unreconciled)
                    ->get(['journal_entry_id', 'payment_number'])
                    ->keyBy('journal_entry_id');

                $journalEntries = DB::table('journal_entries')
                    ->whereIn('id', $unreconciled)
                    ->get(['id', 'entry_number']);

                $documentLinks = $journalEntries->map(function ($je) use ($paymentMap) {
                    $payment = $paymentMap->get($je->id);
                    $title = $payment
                        ? $payment->payment_number
                        : ($je->entry_number ?: "JE #{$je->id}");
                    return [
                        'type'  => 'journal_entry',
                        'id'    => $je->id,
                        'title' => $title,
                    ];
                })->all();

                $upsertTask([
                    'title'       => "Reconcile {$journalEntries->count()} journal entries with unreconciled bank lines",
                    'priority'    => 'high',
                    'is_critical' => false,
                    'metadata'    => [
                        'type'          => 'unreconciled_transactions',
                        'count'         => $journalEntries->count(),
                        'document_links' => $documentLinks,
                    ],
                ]);
            }
        }

        // 5. AP Subledger Reconciliation
        $unpaidBills = DB::table('bills')
            ->leftJoin('vendors', 'bills.vendor_id', '=', 'vendors.id')
            ->whereIn('bills.status', ['posted', 'partial'])
            ->where('bills.balance', '>', 0)
            ->orderBy('bills.id')
            ->limit(50)
            ->get(['bills.id', 'bills.bill_number', 'bills.balance', 'vendors.name as vendor_name']);

        if ($unpaidBills->isNotEmpty()) {
            $apAccount = DB::table('chart_of_accounts')->where('code', '2100')->first();
            $glBalance = 0;
            $subledgerBalance = (float) $unpaidBills->sum('balance');

            if ($apAccount) {
                $credit = (float) DB::table('journal_entry_lines')->where('account_id', $apAccount->id)->sum('credit');
                $debit = (float) DB::table('journal_entry_lines')->where('account_id', $apAccount->id)->sum('debit');
                $glBalance = $credit - $debit;
            }

            $difference = $glBalance - $subledgerBalance;

            $upsertTask([
                'title'       => "Reconcile AP subledger ({$unpaidBills->count()} unpaid bills, \${$subledgerBalance} subledger vs \${$glBalance} GL)",
                'priority'    => 'high',
                'is_critical' => false,
                'metadata'    => [
                    'type'              => 'ap_subledger_reconciliation',
                    'count'             => $unpaidBills->count(),
                    'gl_ap_balance'     => $glBalance,
                    'subledger_balance' => $subledgerBalance,
                    'difference'        => $difference,
                    'document_links'    => $unpaidBills->map(fn ($bill) => [
                        'type'  => 'bill',
                        'id'    => $bill->id,
                        'title' => $bill->bill_number ?: ($bill->vendor_name ? "{$bill->vendor_name} Bill" : "Bill #{$bill->id}"),
                    ])->all(),
                ],
            ]);
        }

        // 6. Default close tasks (always created, idempotent by type)
        $defaultTasks = [
            [
                'title' => 'Review trial balance',
                'priority' => 'high',
                'is_critical' => true,
                'type' => 'review_trial_balance',
                'document_links' => [
                    ['type' => 'report', 'id' => 'trial-balance', 'title' => 'Trial Balance Report'],
                ],
                'depends_on' => ['unposted_journal_entries', 'unpaid_bills', 'overdue_invoices'],
            ],
            [
                'title' => 'Run depreciation entries',
                'priority' => 'medium',
                'is_critical' => false,
                'type' => 'run_depreciation',
                'document_links' => [],
                'depends_on' => ['review_trial_balance'],
            ],
            [
                'title' => 'Review accruals and prepayments',
                'priority' => 'medium',
                'is_critical' => false,
                'type' => 'review_accruals',
                'document_links' => [],
                'depends_on' => ['review_trial_balance'],
            ],
            [
                'title' => 'Generate financial statements',
                'priority' => 'high',
                'is_critical' => true,
                'type' => 'generate_financial_statements',
                'document_links' => [
                    ['type' => 'report', 'id' => 'financial-statements', 'title' => 'Financial Statements'],
                ],
                'depends_on' => ['review_trial_balance', 'run_depreciation', 'review_accruals'],
            ],
            [
                'title' => 'Final review and sign-off',
                'priority' => 'critical',
                'is_critical' => true,
                'type' => 'final_review_signoff',
                'document_links' => [],
                'depends_on' => ['generate_financial_statements'],
            ],
        ];

        foreach ($defaultTasks as $task) {
            $upsertTask([
                'title'       => $task['title'],
                'priority'    => $task['priority'],
                'is_critical' => $task['is_critical'],
                'metadata'    => [
                    'type'          => $task['type'],
                    'document_links' => $task['document_links'],
                ],
            ]);
        }

        // Create dependencies for default tasks
        $allActiveTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->get()
            ->keyBy(fn ($t) => $t->metadata['type'] ?? null)
            ->filter(fn ($t, $k) => $k !== null);

        foreach ($defaultTasks as $task) {
            $currentTask = $allActiveTasks->get($task['type']);
            if (!$currentTask) {
                continue;
            }
            foreach ($task['depends_on'] ?? [] as $depType) {
                $depTask = $allActiveTasks->get($depType);
                if (!$depTask) {
                    continue;
                }
                \Modules\FinancialClose\Models\TaskDependency::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'task_id' => $currentTask->id,
                        'depends_on_task_id' => $depTask->id,
                    ],
                    ['dependency_type' => 'finish_to_start']
                );
            }
        }

        return $count;
    }
}
