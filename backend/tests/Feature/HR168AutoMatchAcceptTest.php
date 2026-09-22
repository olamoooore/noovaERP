<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementMatch;
use App\Models\BankStatementTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reconciliation\AutoMatchingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #168 — the auto-match subsystem never marked GL lines reconciled,
 * required a reconciliation row that no flow could create yet, and persisted
 * suggestions into a disconnected model universe.
 * Issue #101 — getStatistics() called getUnreconciledTransactions() with a
 * drifted signature and return-shape key.
 */
class HR168AutoMatchAcceptTest extends TenantTestCase
{
    private User $user;

    private BankAccount $bankAccount;

    private ChartOfAccount $glAccount;

    private JournalEntryLine $line;

    private string $entryDate = '2026-03-15';

    private string $reference = 'HR168-REF-001';

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '22222222-1111-0000-9999-888888888888',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'bank_accounts', 'journal_entries', 'journal_entry_lines',
            'bank_reconciliations', 'reconciliation_items', 'bank_statement_matches',
            'bank_statement_transactions', 'bank_statement_imports',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'HR168 User',
            'email' => 'hr168-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        $this->glAccount = ChartOfAccount::factory()->create([
            'code' => '1100-HR168',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $this->bankAccount = BankAccount::create([
            'account_name' => 'HR168 Bank',
            'gl_account_id' => $this->glAccount->id,
        ]);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-HR168-'.Str::upper(Str::random(6)),
            'entry_date' => $this->entryDate,
            'description' => 'HR168 journal',
            'reference' => $this->reference,
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        $this->line = JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->glAccount->id,
            'description' => 'HR168 line',
            'reference' => $this->reference,
            'debit' => 100,
            'credit' => 0,
        ]);
    }

    private function statementTransaction(): BankStatementTransaction
    {
        $import = BankStatementImport::create([
            'bank_account_id' => $this->bankAccount->id,
            'file_name' => 'hr168.csv',
            'file_hash' => hash('sha256', 'hr168-'.Str::random(8)),
            'storage_path' => 'statements/hr168.csv',
            'file_type' => 'csv',
            'currency_code' => 'SGD',
            'imported_by' => $this->user->id,
        ]);

        return BankStatementTransaction::create([
            'import_id' => $import->id,
            'bank_account_id' => $this->bankAccount->id,
            'transaction_key' => 'hr168-'.Str::random(10),
            'source_row' => 2,
            'transaction_date' => $this->entryDate,
            'description' => 'HR168 statement txn',
            'amount' => 100,
            'currency_code' => 'SGD',
            'residual_amount' => 100,
            'raw_data' => ['row' => 2],
        ]);
    }

    public function test_accept_without_a_reconciliation_id_marks_the_gl_line_reconciled(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'matches' => [[
                    'bank_transaction_id' => 'legacy-string-id',
                    'gl_transaction_id' => $this->line->id,
                    'match_type' => 'manual',
                    'confidence' => 95,
                ]],
            ]
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.accepted'));
        $this->assertNotNull($response->json('data.reconciliation_id'));

        $line = $this->line->fresh();
        $this->assertNotNull($line->reconciliation_id, 'The GL line must be stamped with the reconciliation');
        $this->assertTrue((bool) $line->reconciled, 'The GL line must be marked reconciled');

        $this->assertSame(1, BankReconciliation::where('bank_account_id', $this->bankAccount->id)->count());
        $this->assertDatabaseHas('reconciliation_items', [
            'journal_entry_line_id' => $this->line->id,
        ]);
    }

    public function test_accept_with_a_real_statement_transaction_accepts_the_statement_match(): void
    {
        $transaction = $this->statementTransaction();

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'matches' => [[
                    'bank_transaction_id' => (string) $transaction->id,
                    'gl_transaction_id' => $this->line->id,
                    'match_type' => 'exact',
                    'confidence' => 100,
                ]],
            ]
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.accepted'), json_encode($response->json('data.errors')));

        $match = BankStatementMatch::where('journal_entry_line_id', $this->line->id)->first();
        $this->assertNotNull($match);
        $this->assertSame('accepted', $match->status);
        $this->assertNotNull($match->reconciliation_id);

        $this->assertDatabaseHas('reconciliation_items', [
            'journal_entry_line_id' => $this->line->id,
            'bank_statement_match_id' => $match->id,
        ]);
    }

    public function test_accept_rejects_a_reconciliation_belonging_to_another_bank_account(): void
    {
        $otherAccount = BankAccount::create([
            'account_name' => 'HR168 Other Bank',
            'gl_account_id' => $this->glAccount->id,
        ]);
        $otherReconciliation = BankReconciliation::create([
            'bank_account_id' => $otherAccount->id,
            'statement_date' => $this->entryDate,
            'statement_balance' => 0,
            'opening_balance' => 0,
            'closing_balance' => 0,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'reconciliation_id' => $otherReconciliation->id,
                'matches' => [[
                    'bank_transaction_id' => 'x',
                    'gl_transaction_id' => $this->line->id,
                    'match_type' => 'manual',
                    'confidence' => 90,
                ]],
            ]
        );

        $response->assertStatus(500);
        $this->assertStringContainsString('does not belong', (string) $response->json('error'));
        $this->assertNull($this->line->fresh()->reconciliation_id);
    }

    public function test_already_reconciled_lines_are_reported_as_failed(): void
    {
        $existing = BankReconciliation::create([
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => $this->entryDate,
            'statement_balance' => 0,
            'opening_balance' => 0,
            'closing_balance' => 0,
            'status' => 'draft',
        ]);
        $this->line->update(['reconciliation_id' => $existing->id, 'reconciled' => true]);

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'matches' => [[
                    'bank_transaction_id' => 'x',
                    'gl_transaction_id' => $this->line->id,
                    'match_type' => 'manual',
                    'confidence' => 90,
                ]],
            ]
        );

        $response->assertOk();
        $this->assertSame(0, $response->json('data.accepted'));
        $this->assertSame(1, $response->json('data.failed'));
    }

    public function test_suggestions_are_persisted_as_bank_statement_matches(): void
    {
        $transaction = $this->statementTransaction();

        $suggestions = app(AutoMatchingService::class)->findMatches(
            $this->bankAccount->id,
            [[
                'id' => (string) $transaction->id,
                'date' => $this->entryDate,
                'amount' => 100,
                'reference' => $this->reference,
                'description' => 'HR168 statement txn',
            ]]
        );

        $this->assertNotEmpty($suggestions[0]['matches']);

        $match = BankStatementMatch::where('bank_statement_transaction_id', $transaction->id)
            ->where('journal_entry_line_id', $this->line->id)
            ->first();

        $this->assertNotNull($match, 'A real BankStatementMatch suggestion must be persisted');
        $this->assertSame('proposed', $match->status);
        $this->assertSame('100.00000000', (string) $match->matched_amount);

        // The disconnected legacy tables must stay empty.
        $this->assertSame(0, DB::table('bank_statement_lines')->count());
        $this->assertSame(0, DB::table('match_candidates')->count());
    }

    public function test_statistics_endpoint_returns_data_instead_of_throwing(): void
    {
        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/accounting/reconciliation/auto-match/statistics/'.$this->bankAccount->id
        );

        $this->assertSame(200, $response->status(), (string) $response->json('error'));
        $this->assertTrue((bool) $response->json('success'));
        $this->assertNotNull($response->json('data'));
        $this->assertSame(1, $response->json('data.total_bank_transactions'));
    }
}
