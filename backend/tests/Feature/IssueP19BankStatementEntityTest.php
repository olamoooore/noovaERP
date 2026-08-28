<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Reconciliation\AutoMatchingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue P1-9: Bank reconciliation validates against the GL directly; no durable
 * statement entity; inconsistent bank-account identity.
 */
class IssueP19BankStatementEntityTest extends TenantTestCase
{
    protected ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::first() ?? User::create([
            'name' => 'Test User',
            'email' => 'test-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    private function makeBankAccount(): BankAccount
    {
        $glAccount = ChartOfAccount::create([
            'code' => 'BANK-'.uniqid(),
            'name' => 'Test Bank GL',
            'type' => 'asset',
            'account_type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);

        return BankAccount::create([
            'account_name' => 'Test Bank Account',
            'account_number' => 'ACC-'.uniqid(),
            'bank_name' => 'Test Bank',
            'currency_code' => 'USD',
            'gl_account_id' => $glAccount->id,
            'is_active' => true,
        ]);
    }

    private function makePostedBankLine(BankAccount $account, float $amount, string $date = '2026-01-15'): JournalEntryLine
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => $date,
            'description' => 'Bank txn',
            'status' => 'posted',
            'type' => 'manual',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        $line = JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->gl_account_id,
            'description' => 'Bank deposit',
            'reference' => 'REF-'.uniqid(),
            'debit' => $amount,
            'credit' => 0,
        ]);

        return $line->fresh();
    }

    public function test_bank_statement_and_line_entities_are_durable(): void
    {
        $account = $this->makeBankAccount();

        $statement = BankStatement::create([
            'bank_account_id' => $account->id,
            'statement_date' => '2026-01-31',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'opening_balance' => 0,
            'closing_balance' => 1000,
            'currency_code' => 'USD',
            'status' => 'imported',
            'source' => 'csv',
        ]);

        $line = BankStatementLine::create([
            'bank_statement_id' => $statement->id,
            'bank_account_id' => $account->id,
            'transaction_date' => '2026-01-15',
            'description' => 'Customer payment',
            'reference' => 'INV-001',
            'debit' => 1000,
            'credit' => 0,
            'amount' => 1000,
            'currency_code' => 'USD',
            'status' => 'unmatched',
        ]);

        $this->assertDatabaseHas('bank_statements', [
            'id' => $statement->id,
            'bank_account_id' => $account->id,
            'status' => 'imported',
        ]);

        $this->assertDatabaseHas('bank_statement_lines', [
            'id' => $line->id,
            'bank_statement_id' => $statement->id,
            'bank_account_id' => $account->id,
            'reference' => 'INV-001',
            'status' => 'unmatched',
        ]);
    }

    public function test_auto_match_uses_bank_account_identity_not_gl_account_identity(): void
    {
        $account = $this->makeBankAccount();
        $glLine = $this->makePostedBankLine($account, 250.00);

        $statement = BankStatement::create([
            'bank_account_id' => $account->id,
            'statement_date' => '2026-01-31',
            'opening_balance' => 0,
            'closing_balance' => 250,
            'status' => 'imported',
        ]);

        $statementLine = BankStatementLine::create([
            'bank_statement_id' => $statement->id,
            'bank_account_id' => $account->id,
            'transaction_date' => '2026-01-15',
            'description' => 'Deposit',
            'reference' => $glLine->reference,
            'debit' => 250,
            'credit' => 0,
            'amount' => 250,
            'status' => 'unmatched',
        ]);

        $service = app(AutoMatchingService::class);
        $suggestions = $service->findMatches($account->id, [
            [
                'id' => (string) $statementLine->id,
                'date' => '2026-01-15',
                'amount' => 250.00,
                'reference' => $glLine->reference,
                'description' => 'Deposit',
            ],
        ]);

        $this->assertCount(1, $suggestions);
        $this->assertSame('exact', $suggestions[0]['matches'][0]['match_type'] ?? null);
        $this->assertSame($glLine->id, $suggestions[0]['matches'][0]['gl_transaction_id'] ?? null);

        $this->assertDatabaseHas('match_candidates', [
            'bank_statement_line_id' => $statementLine->id,
            'journal_entry_line_id' => $glLine->id,
            'match_type' => 'exact',
            'accepted' => false,
        ]);
    }

    public function test_bank_reconciliation_controller_uses_bank_accounts_id(): void
    {
        $account = $this->makeBankAccount();

        $this->actingAs($this->user);

        $response = $this->getJson("/api/v1/accounting/reconciliation/transactions/{$account->id}");

        $response->assertOk();
        $json = $response->json();
        $this->assertSame($account->id, $json['bank_account_id'] ?? null);
        $this->assertSame($account->gl_account_id, $json['gl_account_id'] ?? null);
    }
}
