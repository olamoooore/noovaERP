<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementMatch;
use App\Models\BankStatementTransaction;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #841 (BK9) — the legacy manual completion and the auto-match
 * acceptance each wrote their own reconciliation state with rules the
 * statement-import engine never saw, so a step completed in one flow was
 * invisible to (or blocked by) the other's controls.
 *
 * The statement-import engine is the single reconciliation model:
 *  - manual completion runs on it as a "manual statement" source, so the
 *    continuity/tolerance/audit controls apply to it too;
 *  - an accepted auto-match lands in the statement rows the engine's
 *    validate/complete actually read, and finalisation of the GL line stays
 *    with the engine's complete();
 *  - every reconciliation endpoint is governed by the reconciliation.*
 *    permission set instead of only the statement routes being gated.
 */
class BankReconciliationSingleFlowTest extends TenantTestCase
{
    private User $user;

    private User $outsider;

    private BankAccount $bankAccount;

    private JournalEntryLine $line;

    private BankStatementImport $import;

    private BankStatementTransaction $statementTransaction;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady([
            'bank_accounts', 'journal_entries', 'journal_entry_lines', 'fiscal_periods',
            'bank_reconciliations', 'reconciliation_items',
            'bank_statement_imports', 'bank_statement_transactions', 'bank_statement_matches',
            'bank_statement_operations', 'bank_reconciliation_scopes', 'bank_statement_audit_events',
        ]);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Authenticated, but no role and no tenant access record — the
        // reconciliation.* permission set must answer for them too.
        $this->outsider = User::create([
            'name' => 'BK9 Outsider',
            'email' => 'bk9-outsider-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        $gl = ChartOfAccount::factory()->create([
            'code' => '1100-BK841',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->bankAccount = BankAccount::create([
            'account_name' => 'BK841 Bank',
            'currency_code' => 'USD',
            'gl_account_id' => $gl->id,
            'is_active' => true,
        ]);

        FiscalPeriod::createMonthlyPeriodsForYear(2026);

        $this->line = $this->postedBankLine('BK841-REF', 100.00, '2026-03-15');

        $this->import = BankStatementImport::create([
            'bank_account_id' => $this->bankAccount->id,
            'file_name' => 'bk841.csv',
            'file_hash' => hash('sha256', 'bk841-'.Str::random(8)),
            'storage_path' => 'statements/bk841.csv',
            'file_type' => 'csv',
            'status' => 'imported',
            'statement_start_date' => '2026-03-01',
            'statement_end_date' => '2026-03-31',
            'opening_balance' => 0,
            'closing_balance' => 100,
            'currency_code' => 'USD',
            'imported_by' => $this->user->id,
            'imported_at' => now(),
        ]);

        $this->statementTransaction = BankStatementTransaction::create([
            'import_id' => $this->import->id,
            'bank_account_id' => $this->bankAccount->id,
            'transaction_key' => 'bk841-'.Str::random(10),
            'source_row' => 2,
            'transaction_date' => '2026-03-15',
            'description' => 'BK841 statement line',
            'amount' => 100,
            'currency_code' => 'USD',
            'status' => 'unmatched',
            'residual_amount' => 100,
            'raw_data' => ['row' => 2],
        ]);
    }

    private function postedBankLine(string $reference, float $amount, string $date): JournalEntryLine
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-BK841-'.Str::upper(Str::random(6)),
            'entry_date' => $date,
            'description' => 'BK841 bank movement',
            'reference' => $reference,
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        return JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->bankAccount->gl_account_id,
            'description' => 'BK841 bank movement',
            'reference' => $reference,
            'debit' => $amount,
            'credit' => 0,
        ])->fresh();
    }

    public function test_accepted_auto_match_lands_in_the_statement_engine(): void
    {
        $accept = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'matches' => [[
                    'bank_transaction_id' => (string) $this->statementTransaction->id,
                    'gl_transaction_id' => $this->line->id,
                    'match_type' => 'exact',
                    'confidence' => 100,
                ]],
            ]
        );
        $accept->assertOk();
        $this->assertSame(1, $accept->json('data.accepted'), json_encode($accept->json('data.errors')));

        // Every statement-engine control reads residual_amount/status — an
        // accepted auto-match must be visible there, not just in the match row.
        $transaction = $this->statementTransaction->fresh();
        $this->assertSame('matched', (string) $transaction->status);
        $this->assertEqualsWithDelta(0.0, (float) $transaction->residual_amount, 0.0001);

        $validate = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/statement-reconciliations/validate',
            ['bank_account_id' => $this->bankAccount->id, 'import_ids' => [$this->import->id]]
        )->assertOk();
        $this->assertTrue(
            (bool) $validate->json('data.valid'),
            json_encode($validate->json('data.blockers'))
        );

        $complete = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/statement-reconciliations/complete',
            [
                'bank_account_id' => $this->bankAccount->id,
                'import_ids' => [$this->import->id],
                'idempotency_key' => (string) Str::uuid(),
            ]
        );
        $complete->assertCreated();

        $reconciliationId = $complete->json('data.id');
        $this->assertSame($reconciliationId, $this->line->fresh()->reconciliation_id);
        $match = BankStatementMatch::where('journal_entry_line_id', $this->line->id)
            ->where('status', 'accepted')
            ->first();
        $this->assertNotNull($match);
        $this->assertSame($reconciliationId, $match->reconciliation_id);
    }

    public function test_manual_completion_is_bound_by_statement_continuity(): void
    {
        $first = $this->actingAs($this->user)->postJson('/api/v1/accounting/reconciliation/complete', [
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-31',
            'statement_balance' => 100,
            'opening_balance' => 0,
            'reconciled_transaction_ids' => [$this->line->id],
        ]);
        $first->assertCreated();

        $lineB = $this->postedBankLine('BK841-REF-B', 50.00, '2026-04-15');

        // Typed opening 0 balances against the ticked lines (0 + 50 = 50) but
        // does not tie to the prior completed closing balance (100): the
        // statement engine's continuity control must reject this manual
        // completion exactly as it rejects a statement import.
        $second = $this->actingAs($this->user)->postJson('/api/v1/accounting/reconciliation/complete', [
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2026-04-30',
            'statement_balance' => 50,
            'opening_balance' => 0,
            'reconciled_transaction_ids' => [$lineB->id],
        ]);

        $second->assertStatus(500);
        $this->assertStringContainsString('Opening balance does not agree', (string) $second->json('error'));
        $this->assertNull($lineB->fresh()->reconciliation_id);
        $this->assertSame(1, BankReconciliation::count());
    }

    public function test_legacy_reconciliation_reads_require_the_view_permission(): void
    {
        $this->actingAs($this->outsider)
            ->getJson('/api/v1/accounting/reconciliation/bank-accounts')
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->getJson('/api/v1/accounting/reconciliation/history/'.$this->bankAccount->id)
            ->assertForbidden();

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/reconciliation/bank-accounts')
            ->assertOk();
    }

    public function test_auto_match_writes_require_the_match_permission(): void
    {
        // An accounting writer who does not hold reconciliation.match: the
        // group's generic accounting write gate lets them through, but the
        // auto-match endpoints must be governed by reconciliation.match like
        // every other reconciliation write.
        $writerRole = Role::firstOrCreate(['name' => 'bk9-writer', 'guard_name' => 'web']);
        $writerRole->givePermissionTo('accounting.write');
        $writer = User::factory()->create();
        $writer->assignRole($writerRole);

        $response = $this->actingAs($writer)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'matches' => [[
                    'bank_transaction_id' => (string) $this->statementTransaction->id,
                    'gl_transaction_id' => $this->line->id,
                    'match_type' => 'exact',
                    'confidence' => 100,
                ]],
            ]
        );

        $response->assertForbidden();
        $this->assertSame(
            'reconciliation.match',
            (string) $response->json('required_permission')
        );
        $this->assertSame('unmatched', (string) $this->statementTransaction->fresh()->status);
        $this->assertNull($this->line->fresh()->reconciliation_id);
    }
}
