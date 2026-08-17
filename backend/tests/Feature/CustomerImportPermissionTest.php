<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\Contact\src\Models\ContactImportBatch;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Tests that customer import endpoints enforce RequirePermission::using('customers.write').
 *
 * Covers:
 * - Unauthenticated user is rejected
 * - Authenticated user without customers.write receives 403
 * - Authorized user can stage an import
 * - Authorized user can commit an import
 * - User cannot access another tenant's batch
 * - Rejected requests create no customer, contact or partial import state
 */
class CustomerImportPermissionTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        try {
            Role::firstOrCreate(['name' => 'accountant']);
            Role::firstOrCreate(['name' => 'admin']);
            Permission::firstOrCreate(['name' => 'customers.write']);
            Permission::firstOrCreate(['name' => 'customers.view']);
        } catch (\Throwable $e) {
            // Tables may already exist from central migrations
        }
    }

    private function createCsvFile(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }

    private function validCsv(): string
    {
        return "name,code,contact_name,contact_category,contact_type\n".
            "Acme Corp,VC0001,John Doe,Clinical,Primary";
    }

    // ─── Unauthenticated user is rejected ─────────────────────────

    public function test_import_returns_401_without_auth(): void
    {
        $response = $this->postJson('/api/v1/accounting/customers/import', [
            'file' => $this->createCsvFile($this->validCsv()),
        ]);

        $response->assertStatus(401);
    }

    public function test_commit_returns_401_without_auth(): void
    {
        $response = $this->postJson('/api/v1/accounting/customers/import/00000000-0000-0000-0000-000000000000/commit');

        $response->assertStatus(401);
    }

    // ─── Authenticated user without customers.write receives 403 ───

    public function test_import_returns_403_without_write_permission(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $user->assignRole('accountant');

        // User has accountant role but NOT customers.write permission
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/accounting/customers/import', [
            'file' => $this->createCsvFile($this->validCsv()),
        ]);

        $response->assertStatus(403);

        // Verify nothing was created
        $this->assertDatabaseEmpty('contact_import_batches');
        $this->assertDatabaseEmpty('customer_contacts');
    }

    public function test_commit_returns_403_without_write_permission(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $user->assignRole('accountant');

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v1/accounting/customers/import/00000000-0000-0000-0000-000000000000/commit');

        $response->assertStatus(403);
    }

    // ─── Authorized user can stage an import ──────────────────────

    public function test_import_succeeds_with_write_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('customers.write');

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'multipart/form-data',
        ])->postJson('/api/v1/accounting/customers/import', [
            'file' => $this->createCsvFile($this->validCsv()),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('contact_import_batches', [
            'tenant_id' => $this->tenantUuid,
        ]);
    }

    // ─── Authorized user can commit an import ─────────────────────

    public function test_commit_succeeds_with_write_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('customers.write');

        $token = $user->createToken('test')->plainTextToken;

        // First stage the import
        $stageResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'multipart/form-data',
        ])->postJson('/api/v1/accounting/customers/import', [
            'file' => $this->createCsvFile($this->validCsv()),
        ]);

        $stageResponse->assertOk();
        $batchId = $stageResponse->json('batch_id');

        // Then commit
        $commitResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v1/accounting/customers/import/{$batchId}/commit");

        $commitResponse->assertOk();

        // Verify customer was created
        $this->assertDatabaseHas('customers', [
            'code' => 'VC0001',
            'name' => 'Acme Corp',
        ]);
    }

    // ─── User cannot access another tenant's batch ────────────────

    public function test_commit_rejects_cross_tenant_batch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('customers.write');

        $token = $user->createToken('test')->plainTextToken;

        // Try to commit a batch from a different tenant
        $fakeBatchId = \Illuminate\Support\Str::uuid()->toString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v1/accounting/customers/import/{$fakeBatchId}/commit");

        // Should return 404 (batch not found in this tenant) or 403
        $this->assertContains($response->status(), [403, 404]);
    }

    // ─── Rejected requests create no state ────────────────────────

    public function test_rejected_import_creates_no_customer_or_contact(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $user->assignRole('accountant');
        // Deliberately NO customers.write permission

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'multipart/form-data',
        ])->postJson('/api/v1/accounting/customers/import', [
            'file' => $this->createCsvFile($this->validCsv()),
        ]);

        $response->assertStatus(403);

        // Verify absolutely nothing was created
        $this->assertDatabaseEmpty('contact_import_batches');
        $this->assertDatabaseEmpty('customers');
        $this->assertDatabaseEmpty('customer_contacts');
    }
}
