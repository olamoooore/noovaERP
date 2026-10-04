<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\BankStatementMatch;
use App\Models\BankStatementTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #840 (BK8): confirm() logged rows that failed normalize() and kept
 * going, so a "failed" import still left live, matchable transactions behind:
 * the valid rows were created as unmatched BankStatementTransactions. Row
 * errors were stored with field = null and a generic message, so users could
 * not tell which value was wrong.
 *
 * A failed import is now all-or-nothing for matching: valid rows are recorded
 * then compensated (reversed) in the same run, so no matchable row survives,
 * and every row error names the field and carries a safe preview of the value.
 */
class BankStatementFailedImportOutcomeTest extends TenantTestCase
{
    private User $user;

    private BankAccount $bankAccount;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady([
            'bank_accounts', 'bank_statement_imports', 'bank_statement_transactions',
            'bank_statement_import_errors', 'bank_statement_allocations',
            'bank_statement_matches', 'journal_entries', 'journal_entry_lines',
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

        $gl = ChartOfAccount::factory()->create([
            'code' => '1100-BK840',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->bankAccount = BankAccount::create([
            'account_name' => 'BK840 Bank',
            'currency_code' => 'MYR',
            'gl_account_id' => $gl->id,
            'is_active' => true,
        ]);
    }

    private function previewCsv(string $csv): array
    {
        $file = UploadedFile::fake()->createWithContent('stmt-'.Str::random(6).'.csv', $csv);

        $response = $this->actingAs($this->user)->post('/api/v1/accounting/reconciliation/statement-imports/preview', [
            'file' => $file,
            'bank_account_id' => $this->bankAccount->id,
            'header_row' => 1,
        ], ['Accept' => 'application/json']);

        $response->assertOk();

        return $response->json('data');
    }

    private function confirm(array $preview, array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/accounting/reconciliation/statement-imports', array_merge([
            'preview_token' => $preview['preview_token'],
            'mapping' => $preview['proposed_mapping'],
            'currency_code' => 'MYR',
            'statement_end_date' => '2026-04-30',
            'closing_balance' => 0,
        ], $overrides));
    }

    /**
     * Fixture posted GL line for the bank account, mirroring the established
     * HR168 auto-match test setup (the auto-match endpoint only needs a posted
     * line on the bank's GL account as an input).
     */
    private function postedBankLine(float $amount): JournalEntryLine
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-BK840-'.Str::upper(Str::random(6)),
            'entry_date' => '2026-04-01',
            'description' => 'BK840 bank line',
            'reference' => 'BK840-REF',
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        return JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->bankAccount->gl_account_id,
            'description' => 'BK840 bank line',
            'reference' => 'BK840-REF',
            'debit' => $amount,
            'credit' => 0,
        ])->fresh();
    }

    private const BAD_DATE_CSV = "Date,Description,Amount\n"
        ."2026-04-01,Valid one,10.00\n"
        ."not-a-date,Broken date,20.00\n"
        .'2026-04-03,Valid two,30.00';

    private function failedImport(): string
    {
        $response = $this->confirm($this->previewCsv(self::BAD_DATE_CSV));
        $response->assertCreated();
        $this->assertSame('failed', $response->json('data.status'), 'a row-error import must be failed, not imported');

        return $response->json('data.id');
    }

    public function test_one_bad_date_names_the_field_and_previews_the_value(): void
    {
        $importId = $this->failedImport();

        $errors = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/reconciliation/statement-imports/{$importId}/errors")
            ->assertOk()
            ->json('data');

        $dateErrors = array_values(array_filter($errors, fn ($e) => (int) $e['source_row'] === 3));
        $this->assertCount(1, $dateErrors, 'the broken date row must be a reported row error');
        $this->assertSame('transaction_date', $dateErrors[0]['field'], 'the row error must name the offending field');
        $this->assertSame('not-a-date', $dateErrors[0]['safe_value'], 'the row error must carry a safe preview of the value');
    }

    public function test_failed_import_leaves_no_matchable_transactions(): void
    {
        $importId = $this->failedImport();

        $this->assertSame(0, BankStatementTransaction::where('import_id', $importId)
            ->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->count(),
            'a failed import must not leave matchable statement transactions');

        // AC: continuity stays blocked until the failed import is resolved.
        $validation = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/statement-reconciliations/validate',
            ['bank_account_id' => $this->bankAccount->id, 'import_ids' => [$importId]]
        )->assertOk();
        $this->assertFalse((bool) $validation->json('data.valid'));
        $this->assertContains(
            'IMPORT_NOT_READY',
            collect($validation->json('data.blockers'))->pluck('code')->all(),
            'the failed import must block reconciliation continuity'
        );
    }

    public function test_compensated_rows_cannot_be_accepted_by_auto_match(): void
    {
        $importId = $this->failedImport();
        $glLine = $this->postedBankLine(10.00);
        $statementRow = BankStatementTransaction::where('import_id', $importId)
            ->where('description', 'Valid one')->firstOrFail();

        $autoMatch = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/reconciliation/auto-match/accept',
            [
                'bank_account_id' => $this->bankAccount->id,
                'matches' => [[
                    'bank_transaction_id' => (string) $statementRow->id,
                    'gl_transaction_id' => $glLine->id,
                    'match_type' => 'exact',
                    'confidence' => 100,
                ]],
            ]
        )->assertOk();

        $this->assertSame(0, (int) $autoMatch->json('data.accepted'), 'a failed import row must not be accepted as a match');
        $this->assertSame(1, (int) $autoMatch->json('data.failed'));
        $this->assertSame(0, BankStatementMatch::where('bank_statement_transaction_id', $statementRow->id)->count());
        $this->assertNull($glLine->fresh()->reconciliation_id);
    }
}
