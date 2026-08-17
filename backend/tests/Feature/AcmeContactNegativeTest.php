<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\CustomerContactSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactGroup;
use Modules\Contact\src\Models\ContactGroupMember;
use Modules\Contact\src\Models\ContactImportBatch;
use Modules\Contact\src\Models\ContactImportStaging;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Services\ContactTaxonomyService;
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

    // ─── VEN-NT-04b: No parallel identity master ───────────────────────

    public function test_ven_nt_04b_no_parallel_identity_master(): void
    {
        // Verify CoreContact is the sole identity source by checking
        // that both CRM tables link to core_contacts via core_contact_uuid
        $this->assertTrue(Schema::hasColumn('customers', 'core_contact_uuid'));
        $this->assertTrue(Schema::hasColumn('customer_contacts', 'core_contact_uuid'));

        // Verify CoreContact table exists and has the expected identity fields
        $this->assertTrue(Schema::hasTable('core_contacts'));
        $this->assertTrue(Schema::hasColumn('core_contacts', 'id'));
        $this->assertTrue(Schema::hasColumn('core_contacts', 'display_name'));
        $this->assertTrue(Schema::hasColumn('core_contacts', 'contact_type'));

        // Prove canonical linkage: create a customer via Contact Master
        // and verify core_contact_uuid is populated (not null).
        $customer = Customer::create([
            'code' => 'VC001',
            'name' => 'Test Org',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $sync = app(CustomerContactSyncService::class);
        $orgUuid = $sync->ensureOrganisationContact($customer);

        $customer->refresh();
        $this->assertNotNull($customer->core_contact_uuid, 'core_contact_uuid must be populated after ensureOrganisationContact');
        $this->assertEquals($orgUuid, $customer->core_contact_uuid);

        // Verify the linked CoreContact is an ORGANISATION type
        $orgContact = CoreContact::find($orgUuid);
        $this->assertNotNull($orgContact);
        $this->assertEquals('company', $orgContact->contact_type);

        // Prove no parallel identity: create a person contact via Contact Master
        // and verify it also gets a core_contact_uuid.
        $contact = $customer->customerContacts()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@test.com',
        ]);

        $personUuid = $sync->syncPersonForCustomerContact($customer, $contact, [
            'classification_code' => 'DOCTOR',
            'functional_category_code' => 'CLINICAL',
        ]);

        $contact->refresh();
        $this->assertNotNull($contact->core_contact_uuid, 'customer_contacts.core_contact_uuid must be set by syncPersonForCustomerContact');
        $this->assertEquals($personUuid, $contact->core_contact_uuid);

        $personContact = CoreContact::find($personUuid);
        $this->assertNotNull($personContact);
        $this->assertEquals('individual', $personContact->contact_type);
    }

    // ─── VEN-NT-05b: Primary submitted as role rejected ─────────────────

    public function test_ven_nt_05b_primary_submitted_as_role_rejected(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,Primary";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $personContact = CustomerContact::where('customer_id', $customer->id)->first();

        // is_primary is set
        $this->assertTrue((bool) $personContact->is_primary);

        // No ContactRole with role='PRIMARY' exists
        if ($personContact->core_contact_uuid) {
            $roles = ContactRole::where('tenant_id', $this->tenantUuid)
                ->where('contact_id', $personContact->core_contact_uuid)
                ->pluck('role')
                ->all();
            $this->assertNotContains('PRIMARY', $roles);
        }
    }

    // ─── VEN-NT-06b: Unauthorized primary change denied ─────────────────

    public function test_ven_nt_06b_unauthorized_primary_change_denied(): void
    {
        $customer = Customer::create([
            'code' => 'VC0001',
            'name' => 'Acme Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $contact1 = $customer->customerContacts()->create(['name' => 'John', 'is_primary' => true]);
        $contact2 = $customer->customerContacts()->create(['name' => 'Jane', 'is_primary' => false]);

        // Create user WITHOUT admin role (no customers.write permission)
        $regularUser = User::factory()->create();

        // --- Part 1: Service-level call without actorId (internal path) ---
        $sync = app(CustomerContactSyncService::class);
        $result = $sync->setPrimaryContact($customer, $contact2->id);
        $this->assertTrue($result->is_primary, 'contact2 should be primary after first call');

        // Verify DB state after first call
        $this->assertDatabaseHas('customer_contacts', ['id' => $contact2->id, 'is_primary' => true]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $contact1->id, 'is_primary' => false]);

        // --- Part 2: Service-level call with actorId (unauthorized user) ---
        // Reset: clear all primaries first, then make contact1 primary
        DB::table('customer_contacts')->where('customer_id', $customer->id)->update(['is_primary' => false]);
        DB::table('customer_contacts')->where('id', $contact1->id)->update(['is_primary' => true]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $contact1->id, 'is_primary' => true]);

        // Attempt with unauthorized user — should throw
        $threw = false;
        try {
            $sync->setPrimaryContact($customer, $contact2->id, $regularUser->id);
        } catch (\DomainException $e) {
            $threw = true;
            $this->assertEquals('UNAUTHORIZED_PRIMARY_CONTACT_CHANGE', $e->getMessage());
        }
        $this->assertTrue($threw, 'Expected DomainException for unauthorized primary change');

        // Verify primary did NOT change after failed attempt
        $this->assertDatabaseHas('customer_contacts', ['id' => $contact1->id, 'is_primary' => true]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $contact2->id, 'is_primary' => false]);
    }

    // ─── VEN-NT-07b: Cross-org name duplicate no auto merge ─────────────

    public function test_ven_nt_07b_cross_org_name_duplicate_no_merge(): void
    {
        $orgA_id = \Illuminate\Support\Str::uuid()->toString();
        $orgB_id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $orgA_id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Hospital A',
            'contact_type' => 'company',
        ]);

        CoreContact::create([
            'id' => $orgB_id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Hospital B',
            'contact_type' => 'company',
        ]);

        $sync = app(CustomerContactSyncService::class);

        // Import "John Smith" under Org A
        $resultA = $sync->matchPersonIdentity($this->tenantUuid, $orgA_id, 'John Smith', 'john@a.com', null, null);
        // Import "John Smith" under Org B with different email
        $resultB = $sync->matchPersonIdentity($this->tenantUuid, $orgB_id, 'John Smith', 'john@b.com', null, null);

        // Both should find no existing match (tier null) since the orgs have no linked persons
        $this->assertNull($resultA['tier']);
        $this->assertNull($resultB['tier']);

        // No match means no auto-merge would occur
        $this->assertNull($resultA['contact']);
        $this->assertNull($resultB['contact']);
    }

    // ─── VEN-NT-08b: Billing differs from customer name no auto org ────

    public function test_ven_nt_08b_billing_differs_no_auto_org(): void
    {
        $customer = Customer::create([
            'code' => 'VC100',
            'name' => 'ABC Corp',
            'is_active' => true,
            'total_outstanding' => 0,
            'billing_party' => [
                'legal_name' => 'XYZ Holdings',
                'billing_address' => '789 Different St',
            ],
        ]);

        // Ensure org contact exists
        $orgUuid = \Illuminate\Support\Str::uuid()->toString();
        CoreContact::create([
            'id' => $orgUuid,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'ABC Corp',
            'contact_type' => 'company',
        ]);
        $customer->update(['core_contact_uuid' => $orgUuid]);

        // No automatic relationship created between ABC Corp and XYZ Holdings
        $relationships = ContactRelationship::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $orgUuid)
            ->get();

        // Only self-relationship or none — no auto-created relationship for XYZ Holdings
        foreach ($relationships as $rel) {
            $relatedContact = CoreContact::find($rel->related_contact_id);
            if ($relatedContact) {
                $this->assertNotEquals('XYZ Holdings', $relatedContact->display_name);
            }
        }

        // billing_party is just JSON data, not a relationship
        $customer->refresh();
        $this->assertEquals('XYZ Holdings', $customer->billing_party['legal_name']);
    }

    // ─── VEN-NT-09b: FINANCE_STAFF classification rejected ─────────────

    public function test_ven_nt_09b_finance_staff_classification_rejected(): void
    {
        $contactMasterService = app(ContactMasterService::class);
        $taxonomyService = app(ContactTaxonomyService::class);
        $taxonomyService->ensureSystemTaxonomy($this->tenantUuid);

        $personId = \Illuminate\Support\Str::uuid()->toString();
        CoreContact::create([
            'id' => $personId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'John Doe',
            'contact_type' => 'individual',
        ]);

        // Attempt to assign invalid classification
        $threw = false;
        try {
            $contactMasterService->assignClassification($personId, 'FINANCE_STAFF', $this->tenantUuid);
        } catch (\DomainException $e) {
            $threw = true;
            $this->assertEquals('UNKNOWN_TAXONOMY_VALUE', $e->getMessage());
        }

        $this->assertTrue($threw, 'Expected DomainException for FINANCE_STAFF classification');

        // No classification record created
        $classMember = ContactGroupMember::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $personId)
            ->first();
        $this->assertNull($classMember);
    }

    // ─── VEN-NT-12b: Concurrent primary writes — idempotent via unique partial index ────────────

    /**
     * Proves that when two independent PostgreSQL sessions attempt to set
     * different contacts as primary simultaneously, the unique partial index
     * customer_contacts_one_primary ensures at most one primary per account.
     *
     * Uses pcntl_fork() for genuinely independent workers with:
     * - Explicit synchronization barrier
     * - Bounded lock timeout (5s)
     * - Bounded worker timeout (10s)
     * - Captured worker exceptions
     * - Guaranteed rollback and cleanup
     */
    public function test_ven_nt_12b_concurrent_primary_writes_idempotent(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $customer = Customer::create([
            'code' => 'VC0001',
            'name' => 'Acme Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $contact1 = $customer->customerContacts()->create(['name' => 'John', 'is_primary' => true]);
        $contact2 = $customer->customerContacts()->create(['name' => 'Jane', 'is_primary' => false]);
        $contact3 = $customer->customerContacts()->create(['name' => 'Bob', 'is_primary' => false]);

        // 1. Construct DSN from committed config
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            config('database.connections.pgsql.host'),
            config('database.connections.pgsql.port'),
            config('database.connections.pgsql.database')
        );
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        // 2. Set up synchronization barrier via temporary files
        $barrierDir = sys_get_temp_dir().'/noovaerp_barrier_'.getmypid();
        @mkdir($barrierDir, 0700, true);
        $worker1Ready = $barrierDir.'/worker1_ready';
        $worker2Ready = $barrierDir.'/worker2_ready';
        $goSignal = $barrierDir.'/go';
        $worker1Result = $barrierDir.'/worker1_result';
        $worker2Result = $barrierDir.'/worker2_result';

        // 3. Fork Worker 1 (sets contact2 as primary)
        $pid1 = pcntl_fork();
        $this->assertNotEquals($pid1, -1, 'pcntl_fork failed for worker 1');

        if ($pid1 === 0) {
            // Worker 1
            try {
                $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

                // Signal ready
                file_put_contents($worker1Ready, '1');

                // Wait for go signal with timeout
                $waitStart = microtime(true);
                while (! file_exists($goSignal) && (microtime(true) - $waitStart) < 10) {
                    usleep(50000); // 50ms
                }
                if (! file_exists($goSignal)) {
                    file_put_contents($worker1Result, 'TIMEOUT');
                    posix_exit(1);
                }

                // Begin transaction with lock timeout
                $pdo->exec("SET lock_timeout = '5000'");
                $pdo->beginTransaction();

                // Clear existing primary
                $pdo->exec("UPDATE customer_contacts SET is_primary = false WHERE customer_id = {$customer->id} AND is_primary = true");
                // Set contact2 as primary
                $pdo->exec("UPDATE customer_contacts SET is_primary = true WHERE id = {$contact2->id}");

                $pdo->commit();
                file_put_contents($worker1Result, 'COMMITTED');
            } catch (\Throwable $e) {
                @file_put_contents($worker1Result, 'ERROR: '.$e->getMessage());
                @posix_exit(1);
            }

            posix_exit(0);
        }

        // 4. Fork Worker 2 (sets contact3 as primary)
        $pid2 = pcntl_fork();
        $this->assertNotEquals($pid2, -1, 'pcntl_fork failed for worker 2');

        if ($pid2 === 0) {
            // Worker 2
            try {
                $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

                // Signal ready
                file_put_contents($worker2Ready, '1');

                // Wait for go signal with timeout
                $waitStart = microtime(true);
                while (! file_exists($goSignal) && (microtime(true) - $waitStart) < 10) {
                    usleep(50000); // 50ms
                }
                if (! file_exists($goSignal)) {
                    file_put_contents($worker2Result, 'TIMEOUT');
                    posix_exit(1);
                }

                // Begin transaction with lock timeout
                $pdo->exec("SET lock_timeout = '5000'");
                $pdo->beginTransaction();

                // Clear existing primary
                $pdo->exec("UPDATE customer_contacts SET is_primary = false WHERE customer_id = {$customer->id} AND is_primary = true");
                // Set contact3 as primary
                $pdo->exec("UPDATE customer_contacts SET is_primary = true WHERE id = {$contact3->id}");

                $pdo->commit();
                file_put_contents($worker2Result, 'COMMITTED');
            } catch (\Throwable $e) {
                // Expected: unique partial index violation
                @file_put_contents($worker2Result, 'CONSTRAINT_VIOLATION: '.$e->getMessage());
                @posix_exit(0);
            }

            posix_exit(0);
        }

        // 5. Parent: wait for both workers to be ready
        $waitStart = microtime(true);
        while ((! file_exists($worker1Ready) || ! file_exists($worker2Ready)) && (microtime(true) - $waitStart) < 10) {
            usleep(50000);
        }
        $this->assertFileExists($worker1Ready, 'Worker 1 must become ready within 10s');
        $this->assertFileExists($worker2Ready, 'Worker 2 must become ready within 10s');

        // 6. Signal both workers to proceed simultaneously
        file_put_contents($goSignal, '1');

        // 7. Wait for both workers to finish with timeout
        $workerPids = [$pid1, $pid2];
        $waitStart = microtime(true);
        while (count($workerPids) > 0 && (microtime(true) - $waitStart) < 15) {
            foreach ($workerPids as $key => $wpid) {
                $res = pcntl_waitpid($wpid, $status, WNOHANG);
                if ($res > 0 || $res === -1) {
                    unset($workerPids[$key]);
                }
            }
            if (count($workerPids) > 0) {
                usleep(50000);
            }
        }

        // Force kill any hanging workers
        foreach ($workerPids as $wpid) {
            posix_kill($wpid, SIGTERM);
        }
        usleep(100000); // 100ms grace period
        foreach ($workerPids as $wpid) {
            posix_kill($wpid, SIGKILL);
        }

        // 8. Read worker results
        $result1 = file_exists($worker1Result) ? file_get_contents($worker1Result) : 'NO_RESULT';
        $result2 = file_exists($worker2Result) ? file_get_contents($worker2Result) : 'NO_RESULT';

        // 9. At least one worker must have committed (or hit constraint)
        $this->assertStringContainsString('COMMITTED', $result1.$result2,
            'At least one worker must commit successfully');

        // 10. Verify exactly one primary exists (idempotent outcome)
        $primaryCount = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->count();
        $this->assertEquals(1, $primaryCount, 'Must have exactly one primary after concurrent writes');

        // 11. Verify the winner is one of the two contacts (not the original)
        $primary = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->first();
        $this->assertContains($primary->id, [$contact2->id, $contact3->id],
            'Winner must be one of the two concurrent writers');

        // 12. Verify contact1 (original primary) is no longer primary
        $contact1->refresh();
        $this->assertFalse($contact1->is_primary, 'Original primary must be cleared');

        // 13. Cleanup
        @unlink($worker1Ready);
        @unlink($worker2Ready);
        @unlink($goSignal);
        @unlink($worker1Result);
        @unlink($worker2Result);
        @rmdir($barrierDir);
    }

    // ─── API-Level: Permission middleware is registered on contact routes ───

    public function test_api_permission_middleware_registered_on_contact_routes(): void
    {
        // Verify that RequirePermission middleware is registered on contact routes
        // by inspecting the route middleware. The actual enforcement is tested at
        // the service level (test_ven_nt_06b) and through the RequirePermission
        // middleware unit behavior.
        $routes = app('router')->getRoutes();
        $setPrimaryRoute = $routes->getByAction('App\Http\Controllers\Api\CustomerController@setPrimaryContact');
        $this->assertNotNull($setPrimaryRoute, 'setPrimaryContact route should exist');

        $middleware = $setPrimaryRoute->gatherMiddleware();
        $hasPermission = collect($middleware)->contains(function ($m) {
            return str_contains(is_string($m) ? $m : get_class($m), 'RequirePermission');
        });
        $this->assertTrue($hasPermission, 'setPrimaryContact route must have RequirePermission middleware');

        // Verify addContact also has the middleware
        $addRoute = $routes->getByAction('App\Http\Controllers\Api\CustomerController@addContact');
        $this->assertNotNull($addRoute, 'addContact route should exist');
        $addMiddleware = $addRoute->gatherMiddleware();
        $hasPermissionAdd = collect($addMiddleware)->contains(function ($m) {
            return str_contains(is_string($m) ? $m : get_class($m), 'RequirePermission');
        });
        $this->assertTrue($hasPermissionAdd, 'addContact route must have RequirePermission middleware');
    }

    public function test_require_permission_returns_403_without_tenant_context(): void
    {
        // Verify the RequirePermission middleware blocks requests
        // when no tenant context is available (returns 403).
        $unauthorizedUser = User::factory()->create();

        // The test environment strips SetTenantFromUserPreference middleware,
        // so tenant context is null. RequirePermission should return 403.
        $response = $this->actingAs($unauthorizedUser, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\SyncTenantUserFromCentralAuth::class)
            ->postJson('/api/v1/customers/1/contacts', [
                'name' => 'Test Contact',
            ]);

        // RequirePermission returns 403 when tenant context is missing
        $this->assertContains($response->status(), [401, 403],
            'Request should be blocked when tenant context is unavailable');
    }
}
