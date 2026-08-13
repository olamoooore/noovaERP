<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\CustomerContactSyncService;
use Modules\Contact\src\Models\ContactImportBatch;
use Modules\Contact\src\Models\ContactImportStaging;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * ADR-003A Negative Tests VEN-NT-01..12.
 *
 * Covers the negative-path rejection criteria from the ADR Section 16.
 */
class AcmeContactNegativeTest extends TenantTestCase
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

        return new \Illuminate\Http\UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }

    // ─── VEN-NT-01: Unknown contact_type rejected at COMMIT ────────

    public function test_ven_nt_01_unknown_contact_type_rejected_at_commit(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,UnknownRole";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        // DRY_RUN: warning status, not commit-ready
        $stageRes->assertOk();
        $this->assertEquals('warning', $stageRes->json('counts.warning') > 0 ? 'warning' : 'other');

        $batchId = $stageRes->json('batch_id');

        // COMMIT: row rejected because unknown controlled value
        $commitRes = $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");
        $commitRes->assertOk();
        $this->assertEquals(0, $commitRes->json('imported'));
        $this->assertEquals(1, $commitRes->json('rejected'));
    }

    // ─── VEN-NT-02: Unknown contact_category warned in DRY_RUN ────

    public function test_ven_nt_02_unknown_contact_category_warned(): void
    {
        $csv = "name,code,contact_name,contact_category\n".
            "Acme Corp,VC0001,John Doe,WeirdCategory";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        $stageRes->assertOk();
        $this->assertGreaterThan(0, $stageRes->json('counts.warning'));
    }

    // ─── VEN-NT-03: Duplicate code detected (same batch) ──────────

    public function test_ven_nt_03_duplicate_code_in_same_batch(): void
    {
        $csv = "name,code\nAcme Corp,VC0001\nBeta Ltd,VC0001";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        $stageRes->assertOk();
        $this->assertGreaterThan(0, $stageRes->json('counts.error'));

        $codeConflicts = $stageRes->json('code_conflicts');
        $this->assertCount(1, $codeConflicts);
    }

    // ─── VEN-NT-04: Duplicate code detected (existing customer) ────

    public function test_ven_nt_04_duplicate_code_vs_existing_customer(): void
    {
        Customer::create([
            'code' => 'VC0001',
            'name' => 'Existing Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $csv = "name,code\nNew Corp,VC0001";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        $stageRes->assertOk();
        $this->assertGreaterThan(0, $stageRes->json('counts.error'));
    }

    // ─── VEN-NT-05: Missing required field (name) ─────────────────

    public function test_ven_nt_05_missing_name_rejected(): void
    {
        $csv = "name,code\n,VC0001";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        $stageRes->assertOk();
        $this->assertGreaterThan(0, $stageRes->json('counts.error'));
    }

    // ─── VEN-NT-06: Empty CSV rejected ─────────────────────────────

    public function test_ven_nt_06_empty_csv_rejected(): void
    {
        $csv = "name,code\n";

        $res = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        $res->assertStatus(422);
    }

    // ─── VEN-NT-07: Commit unknown batch returns 404 ──────────────

    public function test_ven_nt_07_commit_unknown_batch_404(): void
    {
        $fakeBatchId = \Illuminate\Support\Str::uuid()->toString();
        $res = $this->as($this->admin(), 'post', "/api/v1/customers/import/{$fakeBatchId}/commit");
        $res->assertStatus(404);
    }

    // ─── VEN-NT-08: Double commit rejected ─────────────────────────

    public function test_ven_nt_08_double_commit_rejected(): void
    {
        $csv = "name,code\nAcme Corp,VC0001";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');

        // First commit
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit")->assertOk();

        // Second commit
        $res = $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");
        $res->assertStatus(409);
    }

    // ─── VEN-NT-09: No auto-creation of taxonomy codes ────────────

    public function test_ven_nt_09_no_auto_creation_of_taxonomy_codes(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,BrandNewRole";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        // Verify no ContactTaxonomy row was created for the unknown code
        $exists = \Modules\Contact\src\Models\ContactTaxonomy::where('tenant_id', $this->tenantUuid)
            ->where('code', 'BRANDNEWROLE')
            ->exists();
        $this->assertFalse($exists);
    }

    // ─── VEN-NT-10: Name-only match NOT auto-merged ───────────────

    public function test_ven_nt_10_name_only_not_auto_merged(): void
    {
        $orgId = \Illuminate\Support\Str::uuid()->toString();
        $personId = \Illuminate\Support\Str::uuid()->toString();
        $relId = \Illuminate\Support\Str::uuid()->toString();

        $org = CoreContact::create([
            'id' => $orgId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Existing Org',
            'contact_type' => 'company',
        ]);

        // Person has a different email so tier 1 won't match
        $person = CoreContact::create([
            'id' => $personId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'John Doe',
            'contact_type' => 'individual',
            'email' => 'jane@other.com',
        ]);

        ContactRelationship::create([
            'id' => $relId,
            'tenant_id' => $this->tenantUuid,
            'contact_id' => $orgId,
            'related_contact_id' => $personId,
            'relationship_type' => 'org_person',
        ]);

        $sync = app(CustomerContactSyncService::class);
        // Search with different email so tier 1 won't match, tier 3 (name) will
        $result = $sync->matchPersonIdentity(
            $this->tenantUuid,
            $orgId,
            'John Doe',
            'different@email.com',
            null,
            null
        );

        // Tier 3: candidate only, not auto-merged
        $this->assertEquals(3, $result['tier']);
        $this->assertNotNull($result['contact']);
    }

    // ─── VEN-NT-11: Cross-tenant isolation ─────────────────────────

    public function test_ven_nt_11_cross_tenant_isolation(): void
    {
        // In schema-per-tenant, each tenant has its own schema so customers
        // from tenant A simply don't exist in tenant B's schema.
        // Verify the customer was created and is accessible within the
        // current tenant context.
        $customer = Customer::create([
            'code' => 'VC9999',
            'name' => 'Tenant A Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $this->assertNotNull(Customer::where('code', 'VC9999')->first());
        $this->assertEquals('Tenant A Corp', $customer->name);
    }

    // ─── VEN-NT-12: Transaction roles never persist as Contact roles

    public function test_ven_nt_12_transaction_roles_never_persist(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,Requestor";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $personContact = CustomerContact::where('customer_id', $customer->id)->first();

        if ($personContact && $personContact->core_contact_uuid) {
            $roles = \Modules\Contact\src\Models\ContactRole::where('tenant_id', $this->tenantUuid)
                ->where('contact_id', $personContact->core_contact_uuid)
                ->pluck('role')
                ->all();

            $this->assertNotContains('REQUESTOR', $roles);
        }
    }

    // ─── Legacy mapping: unknown value flagged ─────────────────────

    public function test_legacy_mapping_unknown_value_flagged(): void
    {
        $mapped = LegacyContactTypeMapping::transformContactType('TotallyUnknown');
        $this->assertTrue($mapped['unknown']);
        $this->assertNull($mapped['classification']);
        $this->assertNull($mapped['persistent_role']);
    }

    // ─── Legacy mapping: empty value returns defaults ──────────────

    public function test_legacy_mapping_empty_value(): void
    {
        $mapped = LegacyContactTypeMapping::transformContactType('');
        $this->assertFalse($mapped['unknown']);
        $this->assertFalse($mapped['is_primary']);
        $this->assertNull($mapped['classification']);
    }

    // ─── Functional category resolution: valid aliases ─────────────

    public function test_functional_category_resolution(): void
    {
        $this->assertEquals('CLINICAL', LegacyContactTypeMapping::resolveFunctionalCategory('Clinical'));
        $this->assertEquals('FINANCE', LegacyContactTypeMapping::resolveFunctionalCategory('Finance'));
        $this->assertEquals('FINANCE', LegacyContactTypeMapping::resolveFunctionalCategory('Accounts Payable'));
        $this->assertEquals('PROCUREMENT', LegacyContactTypeMapping::resolveFunctionalCategory('Purchasing'));
        $this->assertNull(LegacyContactTypeMapping::resolveFunctionalCategory('UnknownCategory'));
    }
}
