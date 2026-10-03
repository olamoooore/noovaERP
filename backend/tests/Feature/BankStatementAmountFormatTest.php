<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #834 (BK2): local bank exports sign amounts with a trailing minus,
 * DR/CR markers or parentheses (all read as +, i.e. withdrawals became
 * deposits) and prefix currency codes like "RM 1,234.56" (read as 0, then
 * silently skipped). Garbage amounts also became silent 0-skips.
 *
 * One parser now handles every pattern; unparsable amounts are row errors
 * that name the field; real zero rows are counted and reported as warnings.
 */
class BankStatementAmountFormatTest extends TenantTestCase
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
            'code' => '1100-BK834',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->bankAccount = BankAccount::create([
            'account_name' => 'BK834 Bank',
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

    public function test_every_sign_and_currency_pattern_keeps_its_meaning_and_garbage_is_a_named_row_error(): void
    {
        $csv = implode("\n", [
            'Date,Description,Amount',
            '2026-04-01,Trailing sign,"1,234.56-"',
            '2026-04-02,Paren negative,"(500.00)"',
            '2026-04-03,DR marker,"300 DR"',
            '2026-04-04,RM deposit,"RM 2,000.00"',
            '2026-04-05,CR marker,"100 CR"',
            '2026-04-06,SGD prefix,"SGD1,234.56"',
            '2026-04-07,Garbage amount,TWO HUNDRED',
        ]);

        $preview = $this->previewCsv($csv);
        $response = $this->confirm($preview);
        $response->assertCreated();

        $importId = $response->json('data.id');
        $this->assertNotNull($importId);

        $amounts = DB::table('bank_statement_transactions')
            ->where('bank_account_id', $this->bankAccount->id)
            ->get()
            ->mapWithKeys(fn ($t) => [$t->description => (float) $t->amount]);

        $this->assertEqualsWithDelta(-1234.56, $amounts['Trailing sign'] ?? null, 0.001, '1,234.56- is a withdrawal');
        $this->assertEqualsWithDelta(-500.0, $amounts['Paren negative'] ?? null, 0.001, '(500.00) is an accounting negative');
        $this->assertEqualsWithDelta(-300.0, $amounts['DR marker'] ?? null, 0.001, '300 DR is a debit');
        $this->assertEqualsWithDelta(2000.0, $amounts['RM deposit'] ?? null, 0.001, 'RM 2,000.00 must not read as 0');
        $this->assertEqualsWithDelta(100.0, $amounts['CR marker'] ?? null, 0.001, '100 CR is a credit');
        $this->assertEqualsWithDelta(1234.56, $amounts['SGD prefix'] ?? null, 0.001, 'SGD1,234.56 must not read as 0');

        $this->assertArrayNotHasKey('Garbage amount', $amounts, 'an unparsable amount must not become a transaction');

        $this->assertSame('failed', $response->json('data.status'), 'a row-error import must be failed, not imported');
        $this->assertSame(1, (int) $response->json('data.error_count'));

        $errors = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/reconciliation/statement-imports/{$importId}/errors")
            ->assertOk()
            ->json('data');

        $amountErrors = array_values(array_filter($errors, fn ($e) => $e['code'] === 'AMOUNT_UNPARSEABLE'));
        $this->assertCount(1, $amountErrors, 'the garbage row must be a reported row error');
        $this->assertSame('amount', $amountErrors[0]['field'], 'the error must name the field');
        $this->assertSame(8, (int) $amountErrors[0]['source_row']);
        $this->assertStringContainsString('TWO HUNDRED', (string) $amountErrors[0]['safe_value']);
    }

    public function test_real_zero_rows_are_counted_and_reported_not_dropped_silently(): void
    {
        $csv = implode("\n", [
            'Date,Description,Amount',
            '2026-04-01,Real row,10.00',
            '2026-04-02,Zero plain,0.00',
            '2026-04-03,Zero prefixed,RM0.00',
        ]);

        $preview = $this->previewCsv($csv);
        $response = $this->confirm($preview);
        $response->assertCreated();

        $importId = $response->json('data.id');
        $this->assertSame('imported', $response->json('data.status'), 'zero rows are warnings, not errors');
        $this->assertSame(1, (int) $response->json('data.transaction_count'), 'only the real row becomes a transaction');

        $import = BankStatementImport::find($importId);
        $this->assertSame(2, (int) ($import->metadata['zero_amount_rows'] ?? -1), 'the zero count must be on the import');

        $errors = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/reconciliation/statement-imports/{$importId}/errors")
            ->assertOk()
            ->json('data');

        $warnings = array_values(array_filter($errors, fn ($e) => $e['code'] === 'ZERO_AMOUNT_ROW'));
        $this->assertCount(2, $warnings, 'each skipped zero row must be reported');
        $this->assertSame('warning', $warnings[0]['severity']);
        $this->assertSame('amount', $warnings[0]['field']);
        $this->assertSame([3, 4], array_map(fn ($w) => (int) $w['source_row'], $warnings));
        $this->assertSame(0, (int) $response->json('data.error_count'), 'warnings must not fail the import');
    }
}
