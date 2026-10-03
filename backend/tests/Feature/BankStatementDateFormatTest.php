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
 * Issue #833 (BK1): without a date_format, parseTransactionDate() rejected its
 * own d/m/Y match (padding) and fell back to Carbon::parse, which reads slash
 * dates m/d/Y — 3/4/2026 silently became 4 March instead of 3 April.
 *
 * The order now comes from the bank format profile or is inferred from the
 * WHOLE column (any day > 12 settles it); an ambiguous column with no profile
 * blocks the import instead of guessing.
 */
class BankStatementDateFormatTest extends TenantTestCase
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
            'code' => '1100-BK833',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->bankAccount = BankAccount::create([
            'account_name' => 'BK833 Bank',
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
            'closing_balance' => 68,
        ], $overrides));
    }

    public function test_column_inference_sets_the_day_month_order_and_dates_are_correct(): void
    {
        // 13/4 has a day > 12 -> the whole column settles on d/m.
        $csv = implode("\n", [
            'Date,Description,Amount,Balance',
            '3/4/2026,Coffee,10.00,100.00',
            '03/04/2026,Tea,5.00,95.00',
            '13/4/2026,Lunch,20.00,75.00',
            '2026-04-03,Dinner,7.00,68.00',
        ]);

        $preview = $this->previewCsv($csv);

        $this->confirm($preview)->assertCreated();

        $dates = DB::table('bank_statement_transactions')
            ->where('bank_account_id', $this->bankAccount->id)
            ->orderBy('transaction_date')
            ->pluck('transaction_date')
            ->all();
        sort($dates);
        $this->assertSame(['2026-04-03', '2026-04-03', '2026-04-03', '2026-04-13'], $dates,
            '3/4, 03/04 and the ISO date are 3 April; 13/4 is 13 April');

        $this->assertSame('d/m/Y', $preview['date_column_format'], 'the preview must show the inferred format');
        $this->assertFalse((bool) $preview['date_column_ambiguous']);
    }

    public function test_ambiguous_column_without_a_profile_blocks_the_import(): void
    {
        // No day > 12 anywhere: d/m vs m/d cannot be inferred.
        $csv = implode("\n", [
            'Date,Description,Amount,Balance',
            '3/4/2026,Coffee,10.00,100.00',
            '05/06/2026,Tea,5.00,95.00',
        ]);

        $preview = $this->previewCsv($csv);

        $this->assertNull($preview['date_column_format']);
        $this->assertTrue((bool) $preview['date_column_ambiguous'], 'the preview must flag the ambiguity');

        $response = $this->confirm($preview);
        $response->assertStatus(422)->assertJsonPath('code', 'INVALID_IMPORT_CONFIRMATION');

        $this->assertStringContainsString('Ambiguous transaction date', $response->decodeResponseJson()['message'] ?? '');
        $this->assertSame(0, BankStatementImport::count(), 'the import must be blocked before anything is created');
    }

    public function test_explicit_profile_resolves_an_ambiguous_column(): void
    {
        $csv = implode("\n", [
            'Date,Description,Amount,Balance',
            '3/4/2026,Coffee,10.00,100.00',
            '05/06/2026,Tea,5.00,95.00',
        ]);

        $preview = $this->previewCsv($csv);

        $this->confirm($preview, ['date_format' => 'd/m/Y'])->assertCreated();

        $dates = DB::table('bank_statement_transactions')
            ->where('bank_account_id', $this->bankAccount->id)
            ->orderBy('transaction_date')
            ->pluck('transaction_date')
            ->all();
        $this->assertSame(['2026-04-03', '2026-06-05'], $dates);
        $this->assertSame(1, BankStatementImport::count());
    }
}
