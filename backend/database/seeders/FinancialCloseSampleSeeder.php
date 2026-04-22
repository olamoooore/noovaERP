<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the "Demo Corp" tenant (e255e52c-a4a6-4158-954a-af04408e9386)
 * with a complete Financial Close scenario so the UI can be tested
 * end-to-end without needing real accounting data.
 *
 * Run: php artisan db:seed --class=FinancialCloseSampleSeeder
 */
class FinancialCloseSampleSeeder extends Seeder
{
    /** The tenant to seed. Change this to any other tenant UUID if needed. */
    private const TENANT_ID = 'e255e52c-a4a6-4158-954a-af04408e9386';

    public function run(): void
    {
        $tenant = \App\Models\Tenant::findOrFail(self::TENANT_ID);

        $tenant->run(function () {
            $tenantId = self::TENANT_ID;

            // ── Pick the first user inside the tenant schema ──────────────────
            $user = \App\Models\User::first();
            if (! $user) {
                $this->command->error('No users found in tenant schema. Run DemoTenantSeeder first.');
                return;
            }
            $userId = (string) $user->id;

            $this->command->info("Seeding Financial Close for tenant: {$tenantId}");
            $this->command->info("User: {$user->name} (id: {$userId})");

            // ── 1. Close Cycle ────────────────────────────────────────────────
            $cycleId = (string) Str::uuid();
            DB::table('fco_close_cycles')->insert([
                'id'         => $cycleId,
                'tenant_id'  => $tenantId,
                'entity_id'  => 'entity-001',
                'period_id'  => '2026-04',
                'status'     => 'opened',
                'created_by' => $userId,
                'opened_at'  => now(),
                'metadata'   => json_encode(['label' => 'April 2026 Close']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command->info("  ✓ Close Cycle created  [ID: {$cycleId}]");

            // ── 2. Tasks ──────────────────────────────────────────────────────
            $taskDefs = [
                [
                    'title'       => 'Reconcile Cash Account (1010)',
                    'status'      => 'assigned',
                    'priority'    => 'high',
                    'is_critical' => false,
                    'due_date'    => now()->addDays(2),
                ],
                [
                    'title'       => 'Review AR Aging Report',
                    'status'      => 'in_progress',
                    'priority'    => 'medium',
                    'is_critical' => false,
                    'due_date'    => now()->addDays(3),
                ],
                [
                    'title'       => 'Approve Payroll Journal Entry',
                    'status'      => 'submitted',
                    'priority'    => 'critical',
                    'is_critical' => true,
                    'due_date'    => now()->addDay(),
                ],
                [
                    'title'       => 'Reconcile AP Subledger',
                    'status'      => 'assigned',
                    'priority'    => 'high',
                    'is_critical' => false,
                    'due_date'    => now()->subDay(),   // overdue — highlighted in red
                ],
                [
                    'title'       => 'Review Inventory Valuation',
                    'status'      => 'completed',
                    'priority'    => 'medium',
                    'is_critical' => false,
                    'due_date'    => now()->subDays(2),
                ],
            ];

            $taskIds = [];
            foreach ($taskDefs as $def) {
                $taskId = (string) Str::uuid();
                DB::table('fco_tasks')->insert([
                    'id'          => $taskId,
                    'tenant_id'   => $tenantId,
                    'cycle_id'    => $cycleId,
                    'title'       => $def['title'],
                    'status'      => $def['status'],
                    'priority'    => $def['priority'],
                    'assigned_to' => $userId,
                    'due_date'    => $def['due_date'],
                    'is_critical' => $def['is_critical'],
                    'metadata'    => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $taskIds[] = $taskId;
            }
            $this->command->info('  ✓ 5 tasks created (1 overdue, 1 submitted, 1 completed)');

            // ── 3. An escalation on the overdue task ──────────────────────────
            DB::table('fco_escalation_events')->insert([
                'id'               => (string) Str::uuid(),
                'tenant_id'        => $tenantId,
                'task_id'          => $taskIds[3], // "Reconcile AP Subledger" (overdue)
                'escalation_level' => 'medium',
                'status'           => 'open',
                'escalated_to'     => null,
                'reason'           => 'Task past due date — automatic escalation triggered.',
                'metadata'         => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $this->command->info('  ✓ Escalation created on overdue task');

            // ── 4. Account Reviews (for readiness + findings) ─────────────────
            $accounts = [
                ['code' => '1010', 'name' => 'Cash',                 'status' => 'approved',     'risk' => 'normal'],
                ['code' => '1200', 'name' => 'Accounts Receivable',  'status' => 'submitted',    'risk' => 'watch'],
                ['code' => '2000', 'name' => 'Accounts Payable',     'status' => 'in_progress',  'risk' => 'normal'],
                ['code' => '4000', 'name' => 'Revenue',              'status' => 'in_progress',  'risk' => 'high'],
                ['code' => '5000', 'name' => 'Cost of Goods Sold',   'status' => 'not_started',  'risk' => 'normal'],
            ];

            $reviewIds = [];
            foreach ($accounts as $acct) {
                $reviewId = (string) Str::uuid();
                DB::table('fci_account_reviews')->insert([
                    'id'          => $reviewId,
                    'tenant_id'   => $tenantId,
                    'entity_id'   => 'entity-001',
                    'book_id'     => 'book-001',
                    'account_code'=> $acct['code'],
                    'account_name'=> $acct['name'],
                    'status'      => $acct['status'],
                    'risk_status' => $acct['risk'],
                    'assigned_to' => $userId,
                    'due_date'    => now()->addDays(5),
                    'metadata'    => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $reviewIds[$acct['code']] = $reviewId;
            }
            $this->command->info('  ✓ 5 account reviews created (1 approved, 1 submitted, 2 in-progress, 1 not-started)');

            // ── 5. Sign-off on approved account ───────────────────────────────
            DB::table('fci_signoffs')->insert([
                'id'        => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'review_id' => $reviewIds['1010'],
                'signed_by' => $userId,
                'role'      => 'accountant',
                'status'    => 'approved',
                'notes'     => 'Cash reconciled. No discrepancies.',
                'signed_at' => now(),
                'created_at'=> now(),
                'updated_at'=> now(),
            ]);
            $this->command->info('  ✓ Sign-off recorded on Cash account');

            // ── 6. AI Findings ────────────────────────────────────────────────
            $findings = [
                [
                    'review_id'   => $reviewIds['4000'],
                    'entity_id'   => 'entity-001',
                    'account_code'=> '4000',
                    'finding_type'=> 'anomaly',
                    'risk_status' => 'high',
                    'confidence'  => 0.87,
                    'description' => 'Revenue in account 4000 increased 42% vs prior period with no corresponding journal entry or supporting document.',
                    'structured_output' => json_encode([
                        'likely_cause'    => 'One-time contract deal — not yet documented in close package',
                        'prior_period'    => 185000,
                        'current_period'  => 263700,
                        'variance'        => 78700,
                        'variance_pct'    => 42.5,
                        'recommendation'  => 'Attach supporting contract or journal entry before sign-off',
                    ]),
                ],
                [
                    'review_id'   => $reviewIds['1200'],
                    'entity_id'   => 'entity-001',
                    'account_code'=> '1200',
                    'finding_type'=> 'missing_support',
                    'risk_status' => 'medium',
                    'confidence'  => 0.79,
                    'description' => 'AR aging shows 3 invoices over 90 days totaling $24,500 with no collection notes or reserve entry.',
                    'structured_output' => json_encode([
                        'likely_cause'   => 'Aging invoices — possible bad debt risk',
                        'amount_at_risk' => 24500,
                        'invoice_count'  => 3,
                        'recommendation' => 'Review for bad debt provision or confirm active collection',
                    ]),
                ],
                [
                    'review_id'   => $reviewIds['5000'],
                    'entity_id'   => 'entity-001',
                    'account_code'=> '5000',
                    'finding_type'=> 'anomaly',
                    'risk_status' => 'critical',
                    'confidence'  => 0.93,
                    'description' => 'COGS-to-revenue ratio dropped from 61% to 38% — significantly below industry benchmark and prior 6-month average.',
                    'structured_output' => json_encode([
                        'likely_cause'       => 'Possible inventory valuation error or misclassified cost',
                        'prior_cogs_ratio'   => '61%',
                        'current_cogs_ratio' => '38%',
                        'recommendation'     => 'Reconcile inventory valuation and verify cost allocation journals',
                    ]),
                ],
            ];

            $firstFindingReviewId = null;
            foreach ($findings as $f) {
                DB::table('fci_ai_findings')->insert([
                    'id'               => (string) Str::uuid(),
                    'tenant_id'        => $tenantId,
                    'review_id'        => $f['review_id'],
                    'entity_id'        => $f['entity_id'],
                    'account_code'     => $f['account_code'],
                    'finding_type'     => $f['finding_type'],
                    'status'           => 'open',
                    'risk_status'      => $f['risk_status'],
                    'confidence'       => $f['confidence'],
                    'description'      => $f['description'],
                    'structured_output'=> $f['structured_output'],
                    'accepted_by'      => null,
                    'accepted_at'      => null,
                    'dismissed_by'     => null,
                    'dismissed_at'     => null,
                    'metadata'         => null,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $firstFindingReviewId = $firstFindingReviewId ?? $f['review_id'];
            }
            $this->command->info('  ✓ 3 AI findings created (1 critical, 1 high, 1 medium)');

            // ── 7. Readiness Snapshot ─────────────────────────────────────────
            DB::table('fci_close_readiness_snapshots')->insert([
                'id'                  => (string) Str::uuid(),
                'tenant_id'           => $tenantId,
                'entity_id'           => 'entity-001',
                'period_id'           => '2026-04',
                'readiness_score'     => 38.0,
                'total_accounts'      => 5,
                'reviewed_accounts'   => 2,
                'signed_off_accounts' => 1,
                'open_blockers'       => 1,
                'open_findings'       => 3,
                'section_breakdown'   => json_encode([
                    ['section' => 'Cash',       'score' => 100, 'status' => 'signed_off'],
                    ['section' => 'AR',         'score' => 50,  'status' => 'submitted'],
                    ['section' => 'AP',         'score' => 30,  'status' => 'in_progress'],
                    ['section' => 'Revenue',    'score' => 20,  'status' => 'in_progress'],
                    ['section' => 'COGS',       'score' => 0,   'status' => 'not_started'],
                ]),
                'metadata'            => null,
                'generated_at'        => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $this->command->info('  ✓ Readiness snapshot created (score: 38%)');

            // ── 8. Print test URLs ────────────────────────────────────────────
            $this->command->newLine();
            $this->command->info('======================================================');
            $this->command->info('  FINANCIAL CLOSE — TEST URLs');
            $this->command->info('======================================================');
            $this->command->info("  Dashboard:       /financial-close");
            $this->command->info("  Cycles:          /financial-close/cycles");
            $this->command->info("  My Tasks:        /financial-close/tasks");
            $this->command->info("  Review Queue:    /financial-close/review-queue");
            $this->command->info("  AI Findings:     /financial-close/findings?reviewId={$reviewIds['4000']}");
            $this->command->info("  Readiness:       /financial-close/readiness?entityId=entity-001&periodId=2026-04");
            $this->command->info("  Forecast:        /financial-close/forecast?cycleId={$cycleId}");
            $this->command->info("  Exec Summary:    /financial-close/executive?entityId=entity-001&periodId=2026-04");
            $this->command->info('======================================================');
            $this->command->info("  Cycle ID:        {$cycleId}");
            $this->command->info("  Revenue ReviewId:{$reviewIds['4000']}");
            $this->command->info('======================================================');
        });
    }
}
