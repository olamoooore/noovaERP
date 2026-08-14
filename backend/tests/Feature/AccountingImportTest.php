<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountingImportService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class AccountingImportTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        Role::firstOrCreate(['name' => 'admin']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function as(User $user, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withoutMiddleware()
            ->json($method, $uri, $data);
    }

    private function createCsvFile(string $csv): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $csv);

        return new \Illuminate\Http\UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    // ─── Positive Tests ──────────────────────────────────────────

    public function test_stage_bill_import(): void
    {
        $csv = "vendor_name,bill_number,total_amount,bill_date\n".
            "Acme Corp,BILL-001,1500.00,2026-08-01\n".
            "Beta Ltd,BILL-002,2500.00,2026-08-02";

        $res = $this->as($this->admin(), 'post', '/api/v1/accounting/import/stage', [
            'file' => $this->createCsvFile($csv),
            'import_type' => 'bill',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.status', 'validated');
    }

    public function test_stage_invoice_import(): void
    {
        $csv = "customer_name,invoice_number,total_amount,invoice_date\n".
            "Customer A,INV-001,5000.00,2026-08-01";

        $res = $this->as($this->admin(), 'post', '/api/v1/accounting/import/stage', [
            'file' => $this->createCsvFile($csv),
            'import_type' => 'invoice',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.valid_rows', 1);
    }

    public function test_stage_journal_entry_import(): void
    {
        $service = app(AccountingImportService::class);
        $user = $this->admin();

        $batch = $service->stageBatch(
            [['entry_number' => 'JE-001', 'description' => 'Test entry', 'lines' => [['account_id' => 1, 'debit' => 1000, 'credit' => 0], ['account_id' => 2, 'debit' => 0, 'credit' => 1000]]]],
            'journal_entry',
            'test.csv',
            'hash123',
            $user->id
        );

        $this->assertEquals(1, $batch->total_rows);
        $this->assertEquals(1, $batch->valid_rows);
    }

    public function test_validation_errors_caught(): void
    {
        $csv = "vendor_name,bill_number,total_amount\n".
            "Acme Corp,BILL-001,\n";

        $res = $this->as($this->admin(), 'post', '/api/v1/accounting/import/stage', [
            'file' => $this->createCsvFile($csv),
            'import_type' => 'bill',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.error_rows', 1)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_get_batch_status(): void
    {
        $service = app(AccountingImportService::class);

        $batch = $service->stageBatch(
            [['vendor_name' => 'Test', 'bill_number' => 'B001', 'total_amount' => 100]],
            'bill',
            'test.csv',
            'hash123',
            $this->admin()->id
        );

        $res = $this->as($this->admin(), 'get', "/api/v1/accounting/import/{$batch->id}/status");
        $res->assertOk()
            ->assertJsonPath('data.id', $batch->id);
    }

    // ─── Negative Tests ──────────────────────────────────────────

    public function test_invalid_import_type_rejected(): void
    {
        $csv = "name,test\nValue1,Value2";

        $res = $this->as($this->admin(), 'post', '/api/v1/accounting/import/stage', [
            'file' => $this->createCsvFile($csv),
            'import_type' => 'invalid_type',
        ]);

        $res->assertStatus(422);
    }

    public function test_missing_file_rejected(): void
    {
        $res = $this->as($this->admin(), 'post', '/api/v1/accounting/import/stage', [
            'import_type' => 'bill',
        ]);

        $res->assertStatus(422);
    }

    public function test_commit_non_validated_batch_fails(): void
    {
        $service = app(AccountingImportService::class);

        $batch = $service->stageBatch(
            [['vendor_name' => 'Test', 'bill_number' => 'B001', 'total_amount' => 100]],
            'bill',
            'test.csv',
            'hash123',
            $this->admin()->id
        );

        $batch->update(['status' => 'pending']);

        $this->expectException(\DomainException::class);
        $service->commitBatch($batch->id, $this->admin()->id);
    }
}
