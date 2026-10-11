<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $this->tenantUuid = (string) Str::uuid();
        // Issue #P2-1 — Pillar 2 Part 1 §3.4 fix: insert the central
        // tenants row before any user_company_access insert (FK target).
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'CustomerImport Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        // Issue #P2-1 — Pillar 2 Part 1 §3.4: this test previously passed
        // only because the schema default of `users.role` was
        // 'platform_super_admin' and RequirePermission's `$user->role === 'platform_super_admin'`
        // branch acted as a global bypass. Per the fix at SHA P2-1,
        // that bypass is closed (role defaults to NULL and the column is
        // removed from $fillable). The test now requires a real
        // user_company_access row that maps the user to this tenant
        // with `admin` role so TenantRoleService::hasPermission
        // returns true for `customers.write`.
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        // Decision (2026-10-07, repo owner, in an AI session): this test checks permissions, not
        // taxonomy. Its CSV uses the healthcare category "Clinical", which since
        // #123 is valid only for tenants with the healthcare vocabulary on, and
        // since #1037 is rejected per row on other tenants. Enable it here so the
        // fixture matches what it was written for.
        \App\Models\Setting::setValue(\Modules\Contact\src\Support\ContactTaxonomyRegistry::HEALTHCARE_SETTING_KEY, true);

        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('customers.write');
        // See P2-1 fix note in test_import_succeeds_with_write_permission.
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
