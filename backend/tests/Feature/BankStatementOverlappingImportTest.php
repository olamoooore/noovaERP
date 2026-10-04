<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\BankStatementTransaction;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #839 (BK7): transaction_key was built from source_file_hash and
 * source_row, so the same bank transaction in two overlapping exports (a 1–15
 * file and a 1–31 file of the same month, or a re-download) received a
 * different key and was imported twice.
 *
 * The key is now content-derived (account + date + amount + bank reference +
 * running balance), with an occurrence counter for identical same-day rows
 * inside one file, so an overlapping range creates no new rows; a preview of
 * an overlapping file flags the already-imported rows, and the operator can
 * choose to skip them (default) or import anyway.
 */
class BankStatementOverlappingImportTest extends TenantTestCase
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
            'code' => '1100-BK839',
            'name' => 'Bank GL',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->bankAccount = BankAccount::create([
            'account_name' => 'BK839 Bank',
            'currency_code' => 'MYR',
            'gl_account_id' => $gl->id,
            'is_active' => true,
        ]);
    }

    private const FIRST_HALF_CSV = "Date,Description,Bank Reference,Amount,Balance\n"
        ."2026-04-02,Payment Alpha,REF-001,-100.00,900.00\n"
        ."2026-04-05,Deposit Beta,REF-002,500.00,1400.00\n"
        .'2026-04-08,Payment Gamma,REF-003,-50.00,1350.00';

    private const FULL_MONTH_CSV = "Date,Description,Bank Reference,Amount,Balance\n"
        ."2026-04-02,Payment Alpha,REF-001,-100.00,900.00\n"
        ."2026-04-05,Deposit Beta,REF-002,500.00,1400.00\n"
        ."2026-04-08,Payment Gamma,REF-003,-50.00,1350.00\n"
        ."2026-04-20,Payment Delta,REF-004,-70.00,1280.00\n"
        .'2026-04-25,Deposit Epsilon,REF-005,200.00,1480.00';

    private const IDENTICAL_ROWS_CSV = "Date,Description,Bank Reference,Amount\n"
        ."2026-04-10,Cash withdrawal,,-25.00\n"
        .'2026-04-10,Cash withdrawal,,-25.00';

    private const IDENTICAL_ROWS_PLUS_NEW_CSV = "Date,Description,Bank Reference,Amount\n"
        ."2026-04-10,Cash withdrawal,,-25.00\n"
        ."2026-04-10,Cash withdrawal,,-25.00\n"
        .'2026-04-11,ATM fee,,-2.00';

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

    private function importCsv(string $csv, array $overrides = []): array
    {
        $preview = $this->previewCsv($csv);
        $response = $this->confirm($preview, $overrides);
        $response->assertCreated();

        return ['preview' => $preview, 'response' => $response];
    }

    private function activeTransactionCount(): int
    {
        return BankStatementTransaction::where('bank_account_id', $this->bankAccount->id)
            ->where('status', '!=', 'reversed')
            ->count();
    }

    public function test_first_half_then_full_month_export_creates_no_duplicate_transactions(): void
    {
        $this->importCsv(self::FIRST_HALF_CSV);
        $this->assertSame(3, $this->activeTransactionCount());

        $second = $this->importCsv(self::FULL_MONTH_CSV);

        $this->assertSame(5, $this->activeTransactionCount(),
            'the 1–15 and 1–31 exports overlap: only the 5 distinct month rows may exist');
        $this->assertSame(2, (int) $second['response']->json('data.transaction_count'),
            'only the two rows beyond the first export are new');
        $this->assertSame(3, (int) $second['response']->json('data.duplicate_count'),
            'the three overlapping rows are recognised as duplicates, not imported twice');
    }

    public function test_preview_of_an_overlapping_export_flags_the_already_imported_rows(): void
    {
        $this->importCsv(self::FIRST_HALF_CSV);

        $preview = $this->previewCsv(self::FULL_MONTH_CSV);

        $this->assertSame(3, (int) ($preview['duplicate_row_count'] ?? 0),
            'the preview must flag the three rows that already exist for this account');
        $sourceRows = array_map(fn ($row) => (int) $row['source_row'], $preview['duplicate_rows'] ?? []);
        $this->assertSame([2, 3, 4], $sourceRows,
            'the flagged rows are the overlapping source rows of the new file');
    }

    public function test_import_anyway_records_the_overlapping_rows_again_on_request(): void
    {
        $this->importCsv(self::FIRST_HALF_CSV);

        $preview = $this->previewCsv(self::FULL_MONTH_CSV);
        $this->assertSame(3, (int) ($preview['duplicate_row_count'] ?? 0));

        $response = $this->confirm($preview, ['import_duplicates' => true]);
        $response->assertCreated();

        $this->assertSame(8, $this->activeTransactionCount(),
            'choosing "import anyway" keeps the 3 originals and records all 5 rows of the overlapping file');
        $this->assertSame(5, (int) $response->json('data.transaction_count'));
    }

    public function test_identical_same_day_rows_keep_their_place_across_overlapping_exports(): void
    {
        $this->importCsv(self::IDENTICAL_ROWS_CSV);
        $this->assertSame(2, $this->activeTransactionCount(),
            'two genuinely identical same-day rows are two transactions, not one');

        $second = $this->importCsv(self::IDENTICAL_ROWS_PLUS_NEW_CSV);

        $this->assertSame(3, $this->activeTransactionCount(),
            'the overlapping copies of both identical rows must not be imported again');
        $this->assertSame(1, (int) $second['response']->json('data.transaction_count'));
        $this->assertSame(2, (int) $second['response']->json('data.duplicate_count'));
    }
}
