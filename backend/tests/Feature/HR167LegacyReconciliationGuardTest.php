<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * Issue #167 — the legacy POST /reconciliation/complete path bypassed the
 * statement-reconciliation system's financial controls and could strand
 * accepted BankStatementMatch rows forever.
 */
class HR167LegacyReconciliationGuardTest extends TenantTestCase
{
    private User $user;

    private BankAccount $bankAccount;

    private JournalEntryLine $line;

    private string $statementDate = '2026-03-31';

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '33333333-2222-1111-0000-999999999999',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'bank_accounts', 'journal_entries', 'journal_entry_lines',
            'fiscal_periods', 'bank_reconciliations', 'bank_statement_matches',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'HR167 User',
            'email' => 'hr167-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        $gl = ChartOfAccount::factory()->create([
            'code' => '1100-HR167',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $this->bankAccount = BankAccount::create([
            'account_name' => 'HR167 Bank',
            'gl_account_id' => $gl->id,
        ]);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-HR167-'.Str::upper(Str::random(6)),
            'entry_date' => $this->statementDate,
            'created_by' => $this->user->id,
        ]);

        $this->line = JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $gl->id,
            'debit' => 100,
            'credit' => 0,
        ]);

        FiscalPeriod::createMonthlyPeriodsForYear(2026);
    }

    private function completePayload(): array
    {
        return [
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => $this->statementDate,
            'statement_balance' => 100,
            'opening_balance' => 0,
            'reconciled_transaction_ids' => [$this->line->id],
        ];
    }

    private function acceptedMatch(): void
    {
        $import = BankStatementImport::create([
            'bank_account_id' => $this->bankAccount->id,
            'file_name' => 'hr167.csv',
            'file_hash' => hash('sha256', 'hr167-'.Str::random(8)),
            'storage_path' => 'statements/hr167.csv',
            'file_type' => 'csv',
            'currency_code' => 'SGD',
            'imported_by' => $this->user->id,
        ]);

        $transaction = BankStatementTransaction::create([
            'import_id' => $import->id,
            'bank_account_id' => $this->bankAccount->id,
            'transaction_key' => 'hr167-'.Str::random(10),
            'source_row' => 2,
            'transaction_date' => $this->statementDate,
            'description' => 'HR167 statement line',
            'amount' => 100,
            'currency_code' => 'SGD',
            'residual_amount' => 0,
            'raw_data' => ['row' => 2],
        ]);

        BankStatementMatch::create([
            'bank_statement_transaction_id' => $transaction->id,
            'journal_entry_line_id' => $this->line->id,
            'matched_amount' => 100,
            'match_type' => 'manual',
            'status' => 'accepted',
        ]);
    }

    public function test_lines_with_accepted_statement_matches_are_rejected_and_directed_to_the_statement_flow(): void
    {
        $this->acceptedMatch();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/reconciliation/complete', $this->completePayload());

        $response->assertStatus(500);
        $this->assertStringContainsString('statement-reconciliation workflow', (string) $response->json('error'));

        // Nothing was reconciled and the pending match is untouched.
        $this->assertNull($this->line->fresh()->reconciliation_id);
        $this->assertSame(0, BankReconciliation::count());
        $this->assertSame('accepted', BankStatementMatch::first()->status);
    }

    public function test_completing_in_a_locked_fiscal_period_is_rejected(): void
    {
        FiscalPeriod::where('period_start', '<=', $this->statementDate)
            ->where('period_end', '>=', $this->statementDate)
            ->update(['status' => 'locked']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/reconciliation/complete', $this->completePayload());

        $response->assertStatus(500);
        $this->assertStringContainsString('is not open', (string) $response->json('error'));
        $this->assertNull($this->line->fresh()->reconciliation_id);
    }

    public function test_open_period_and_no_pending_matches_still_completes(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/reconciliation/complete', $this->completePayload());

        $response->assertStatus(201);
        $this->assertSame(1, BankReconciliation::count());
        $this->assertNotNull($this->line->fresh()->reconciliation_id);
    }
}
