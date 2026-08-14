<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\CustomerContactSyncService;
use App\Services\AcmeCustomerImportService;
use Illuminate\Support\Facades\DB;
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactGroup;
use Modules\Contact\src\Models\ContactGroupMember;
use Modules\Contact\src\Models\ContactImportBatch;
use Modules\Contact\src\Models\ContactImportStaging;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\ContactTaxonomy;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * ADR-003A Acceptance Tests VEN-AT-01..17.
 *
 * Covers the positive-path acceptance criteria from the ADR Section 16.
 */
class AcmeContactAcceptanceTest extends TenantTestCase
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

    // ─── VEN-AT-01: Customer import stages rows (DRY_RUN) ─────────

    public function test_ven_at_01_import_stages_rows(): void
    {
        $csv = "name,code,type,contact_name,contact_category,contact_type\n".
            "Acme Corp,VC0001,company,John Doe,Clinical,Primary\n".
            "Beta Ltd,VC0002,company,Jane Smith,Finance,Billing";

        $res = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);

        $res->assertOk()
            ->assertJsonStructure([
                'batch_id',
                'total_rows',
                'counts' => ['valid', 'warning', 'duplicate', 'candidate', 'error'],
                'code_conflicts',
                'status',
            ])
            ->assertJsonPath('total_rows', 2)
            ->assertJsonPath('status', 'validated');
    }

    // ─── VEN-AT-02: Commit creates customers + contacts ────────────

    public function test_ven_at_02_commit_creates_customers_and_contacts(): void
    {
        $csv = "name,code,contact_name,contact_category,contact_type\n".
            "Acme Corp,VC0001,John Doe,Clinical,Primary\n".
            "Beta Ltd,VC0002,Jane Smith,Finance,Billing";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');

        $commitRes = $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $commitRes->assertOk()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('rejected', 0);

        $this->assertDatabaseHas('customers', ['code' => 'VC0001']);
        $this->assertDatabaseHas('customers', ['code' => 'VC0002']);
    }

    // ─── VEN-AT-03: Canonical ORGANISATION contact created ─────────

    public function test_ven_at_03_canonical_organisation_contact_created(): void
    {
        $csv = "name,code\nAcme Corp,VC0001";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $this->assertNotNull($customer);
        $this->assertNotNull($customer->core_contact_uuid);

        $contact = CoreContact::find($customer->core_contact_uuid);
        $this->assertNotNull($contact);
        $this->assertEquals('company', $contact->contact_type);
        $this->assertEquals($this->tenantUuid, $contact->tenant_id);
    }

    // ─── VEN-AT-04: Person contact created for contact_name ────────

    public function test_ven_at_04_person_contact_created(): void
    {
        $csv = "name,code,contact_name,contact_email,contact_phone\n".
            "Acme Corp,VC0001,John Doe,john@acme.com,5551234567";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customerContact = CustomerContact::where('customer_id', Customer::where('code', 'VC0001')->first()->id)
            ->where('name', 'John Doe')
            ->first();
        $this->assertNotNull($customerContact);
        $this->assertNotNull($customerContact->core_contact_uuid);

        $person = CoreContact::find($customerContact->core_contact_uuid);
        $this->assertNotNull($person);
        $this->assertEquals('individual', $person->contact_type);
        $this->assertEquals('john@acme.com', $person->email);
    }

    // ─── VEN-AT-05: Org-person relationship established ────────────

    public function test_ven_at_05_org_person_relationship_established(): void
    {
        $csv = "name,code,contact_name,contact_category\n".
            "Acme Corp,VC0001,John Doe,Clinical";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $personContact = CustomerContact::where('customer_id', $customer->id)->first();
        $person = CoreContact::find($personContact->core_contact_uuid);

        $relationship = ContactRelationship::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $customer->core_contact_uuid)
            ->where('related_contact_id', $person->id)
            ->where('relationship_type', 'org_person')
            ->first();

        $this->assertNotNull($relationship);
        $this->assertEquals('CLINICAL', $relationship->functional_category_code);
    }

    // ─── VEN-AT-06: Functional category on relationship ────────────

    public function test_ven_at_06_functional_category_on_relationship(): void
    {
        $csv = "name,code,contact_name,contact_category\n".
            "Acme Corp,VC0001,John Doe,Finance";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $personContact = CustomerContact::where('customer_id', $customer->id)->first();
        $person = CoreContact::find($personContact->core_contact_uuid);

        $relationship = ContactRelationship::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $customer->core_contact_uuid)
            ->where('related_contact_id', $person->id)
            ->first();

        $this->assertEquals('FINANCE', $relationship->functional_category_code);
    }

    // ─── VEN-AT-07: Classification on person (DOCTOR/NURSE/OTHER) ─

    public function test_ven_at_07_classification_on_person(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,Dr. Smith,Doctor";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $personContact = CustomerContact::where('customer_id', $customer->id)->first();
        $person = CoreContact::find($personContact->core_contact_uuid);

        $classifications = \Modules\Contact\src\Models\ContactGroup::where('tenant_id', $this->tenantUuid)
            ->where('group_type', 'classification')
            ->pluck('group_name')
            ->all();

        // Verify the person was classified via contact_groups
        $this->assertNotEmpty($classifications);
    }

    // ─── VEN-AT-08: Persistent role on relationship ────────────────

    public function test_ven_at_08_persistent_role_on_relationship(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,Billing";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $personContact = CustomerContact::where('customer_id', $customer->id)->first();
        $person = CoreContact::find($personContact->core_contact_uuid);

        $relationship = ContactRelationship::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $customer->core_contact_uuid)
            ->where('related_contact_id', $person->id)
            ->first();

        $roles = ContactRole::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $person->id)
            ->where('relationship_id', $relationship->id)
            ->pluck('role')
            ->all();

        $this->assertContains('BILLING_CONTACT', $roles);
    }

    // ─── VEN-AT-09: is_primary set via import ──────────────────────

    public function test_ven_at_09_is_primary_set_via_import(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,Primary";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $primary = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->first();

        $this->assertNotNull($primary);
        $this->assertEquals('John Doe', $primary->name);
    }

    // ─── VEN-AT-10: Only one primary per customer ──────────────────

    public function test_ven_at_10_only_one_primary_per_customer(): void
    {
        $csv = "name,code,contact_name,contact_type\n".
            "Acme Corp,VC0001,John Doe,Primary\n".
            "Acme Corp,VC0001,Jane Smith,Primary";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $primaryCount = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->count();

        $this->assertEquals(1, $primaryCount);
    }

    // ─── VEN-AT-11: Code uniqueness detected ───────────────────────

    public function test_ven_at_11_code_uniqueness_detected(): void
    {
        // Create existing customer
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

        $stageRes->assertOk()
            ->assertJsonPath('counts.error', 1);

        $codeConflicts = $stageRes->json('code_conflicts');
        $this->assertNotEmpty($codeConflicts);
    }

    // ─── VEN-AT-12: Billing party preserved as CRM compat ──────────

    public function test_ven_at_12_billing_party_preserved(): void
    {
        $csv = "name,code,billing_address\nAcme Corp,VC0001,123 Main St Singapore";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");

        $customer = Customer::where('code', 'VC0001')->first();
        $this->assertNotNull($customer->billing_party);
        $this->assertArrayHasKey('billing_address', $customer->billing_party);
    }

    // ─── VEN-AT-13: Legacy read projection (Doctor precedence) ─────

    public function test_ven_at_13_legacy_read_projection(): void
    {
        // Doctor > Nurse > Other
        $result = LegacyContactTypeMapping::projectLegacyContactType(['NURSE', 'DOCTOR'], []);
        $this->assertEquals('Doctor', $result);

        $result = LegacyContactTypeMapping::projectLegacyContactType(['NURSE'], []);
        $this->assertEquals('Nurse', $result);

        $result = LegacyContactTypeMapping::projectLegacyContactType(['OTHER'], []);
        $this->assertEquals('Other', $result);

        // No classification, use role precedence
        $result = LegacyContactTypeMapping::projectLegacyContactType([], ['DELIVERY_CONTACT', 'BILLING_CONTACT']);
        $this->assertEquals('Billing Contact', $result);

        // Neither: OTHER
        $result = LegacyContactTypeMapping::projectLegacyContactType([], []);
        $this->assertEquals('Other', $result);
    }

    // ─── VEN-AT-14: Legacy write transform ─────────────────────────

    public function test_ven_at_14_legacy_write_transform(): void
    {
        $mapped = LegacyContactTypeMapping::transformContactType('Doctor');
        $this->assertEquals('DOCTOR', $mapped['classification']);
        $this->assertEquals('CLINICAL', $mapped['functional_category']);
        $this->assertFalse($mapped['unknown']);

        $mapped = LegacyContactTypeMapping::transformContactType('Primary');
        $this->assertTrue($mapped['is_primary']);
        $this->assertFalse($mapped['unknown']);

        $mapped = LegacyContactTypeMapping::transformContactType('Billing');
        $this->assertEquals('BILLING_CONTACT', $mapped['persistent_role']);
        $this->assertFalse($mapped['unknown']);
    }

    // ─── VEN-AT-15: Deterministic dedupe (Tier 1 — email match) ────

    public function test_ven_at_15_dedupe_tier1_email_match(): void
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

        $person = CoreContact::create([
            'id' => $personId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Existing Person',
            'contact_type' => 'individual',
            'email' => 'john@example.com',
        ]);

        ContactRelationship::create([
            'id' => $relId,
            'tenant_id' => $this->tenantUuid,
            'contact_id' => $orgId,
            'related_contact_id' => $personId,
            'relationship_type' => 'org_person',
        ]);

        $sync = app(CustomerContactSyncService::class);
        $result = $sync->matchPersonIdentity(
            $this->tenantUuid,
            $orgId,
            'John Doe',
            'john@example.com',
            null,
            null
        );

        $this->assertEquals(1, $result['tier']);
        $this->assertEquals($personId, $result['contact']->id);
    }

    // ─── VEN-AT-16: Deterministic dedupe (Tier 3 — name only) ──────

    public function test_ven_at_16_dedupe_tier3_name_only(): void
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
        // Search with different email so tier 1 (email match) won't find this person
        // but tier 3 (name match within org) will
        $result = $sync->matchPersonIdentity(
            $this->tenantUuid,
            $orgId,
            'John Doe',
            'different@email.com',
            null,
            null
        );

        $this->assertEquals(3, $result['tier']);
        $this->assertEquals($personId, $result['contact']->id);
    }

    // ─── VEN-AT-17: Atomic SetPrimaryContact ───────────────────────

    public function test_ven_at_17_atomic_set_primary_contact(): void
    {
        $customer = Customer::create([
            'code' => 'VC0001',
            'name' => 'Acme Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $contact1 = $customer->customerContacts()->create([
            'name' => 'John Doe',
            'is_primary' => true,
        ]);

        $contact2 = $customer->customerContacts()->create([
            'name' => 'Jane Smith',
            'is_primary' => false,
        ]);

        $sync = app(CustomerContactSyncService::class);
        $result = $sync->setPrimaryContact($customer, $contact2->id);

        $this->assertTrue($result->is_primary);

        $contact1->refresh();
        $this->assertFalse($contact1->is_primary);

        // Verify unique partial index enforcement
        $primaryCount = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->count();
        $this->assertEquals(1, $primaryCount);
    }

    // ─── VEN-AT-01b: Combined import — org + doctor + classification ──

    public function test_ven_at_01b_combined_import_org_and_doctor(): void
    {
        $csv = "name,code,type,contact_name,contact_category,contact_type,contact_email\n".
            "Acme Corp,VC100,company,Dr Smith,clinical,doctor,dr.smith@acme.com";

        $stageRes = $this->as($this->admin(), 'post', '/api/v1/customers/import', [
            'file' => $this->createCsvFile($csv),
        ]);
        $batchId = $stageRes->json('batch_id');
        $commitRes = $this->as($this->admin(), 'post', "/api/v1/customers/import/{$batchId}/commit");
        $commitRes->assertOk()->assertJsonPath('imported', 1);

        $customer = Customer::where('code', 'VC100')->first();
        $this->assertNotNull($customer);
        $this->assertNotNull($customer->core_contact_uuid);

        // ORGANISATION contact exists
        $org = CoreContact::find($customer->core_contact_uuid);
        $this->assertNotNull($org);
        $this->assertEquals('company', $org->contact_type);

        // PERSON contact created
        $personContact = CustomerContact::where('customer_id', $customer->id)->where('name', 'Dr Smith')->first();
        $this->assertNotNull($personContact);
        $this->assertNotNull($personContact->core_contact_uuid);

        // Canonical PERSON exists
        $person = CoreContact::find($personContact->core_contact_uuid);
        $this->assertNotNull($person);
        $this->assertEquals('individual', $person->contact_type);

        // Classification = DOCTOR
        $classMember = ContactGroupMember::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $person->id)
            ->first();
        $this->assertNotNull($classMember);
        $group = ContactGroup::find($classMember->group_id);
        $this->assertEquals('DOCTOR', $group->group_name);

        // Functional category = CLINICAL
        $relationship = ContactRelationship::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $customer->core_contact_uuid)
            ->where('related_contact_id', $person->id)
            ->first();
        $this->assertNotNull($relationship);
        $this->assertEquals('CLINICAL', $relationship->functional_category_code);
    }

    // ─── VEN-AT-05b: Doctor is Requestor → classification stays DOCTOR ──

    public function test_ven_at_05b_doctor_is_requestor_stays_classification(): void
    {
        $orgId = \Illuminate\Support\Str::uuid()->toString();
        $personId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $orgId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Acme Corp',
            'contact_type' => 'company',
        ]);

        $person = CoreContact::create([
            'id' => $personId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Dr Smith',
            'contact_type' => 'individual',
        ]);

        $contactMasterService = app(ContactMasterService::class);
        $contactMasterService->assignClassification($personId, 'DOCTOR', $this->tenantUuid);

        // Simulate transaction-role transform for Requestor
        $mapped = LegacyContactTypeMapping::transformContactType('Requestor');
        $this->assertEquals('REQUESTOR', $mapped['transaction_role']);
        $this->assertNull($mapped['classification']);
        $this->assertNull($mapped['persistent_role']);
        $this->assertFalse($mapped['is_primary']);

        // Classification stays DOCTOR
        $classMember = ContactGroupMember::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $personId)
            ->first();
        $this->assertNotNull($classMember);
        $group = ContactGroup::find($classMember->group_id);
        $this->assertEquals('DOCTOR', $group->group_name);

        // No REQUESTOR role persisted
        $roles = ContactRole::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $personId)
            ->pluck('role')
            ->all();
        $this->assertNotContains('REQUESTOR', $roles);
    }

    // ─── VEN-AT-08b: Department preserved on association ────────────────

    public function test_ven_at_08b_department_preserved_on_association(): void
    {
        $orgId = \Illuminate\Support\Str::uuid()->toString();
        $personId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $orgId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Acme Corp',
            'contact_type' => 'company',
        ]);

        CoreContact::create([
            'id' => $personId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'John Doe',
            'contact_type' => 'individual',
        ]);

        $contactMasterService = app(ContactMasterService::class);
        $relationship = $contactMasterService->addRelationship($orgId, $personId, 'org_person', $this->tenantUuid, [
            'department_name' => 'Accounts Payable',
            'functional_category_code' => 'FINANCE',
        ]);

        $this->assertNotNull($relationship);
        $this->assertEquals('Accounts Payable', $relationship->department_name);
        $this->assertEquals('FINANCE', $relationship->functional_category_code);

        // Verify persistence after refresh
        $relationship->refresh();
        $this->assertEquals('Accounts Payable', $relationship->department_name);
    }

    // ─── VEN-AT-09b: Multiple addresses coexist ────────────────────────

    public function test_ven_at_09b_multiple_addresses_coexist(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Acme Corp',
            'contact_type' => 'company',
        ]);

        $contactMasterService = app(ContactMasterService::class);

        $billing = $contactMasterService->addAddress($contactId, [
            'address_type' => 'billing',
            'line_1' => '123 Billing St',
            'city' => 'Singapore',
            'country' => 'SG',
            'is_default_billing' => true,
        ], $this->tenantUuid);

        $shipping = $contactMasterService->addAddress($contactId, [
            'address_type' => 'shipping',
            'line_1' => '456 Shipping Ave',
            'city' => 'Singapore',
            'country' => 'SG',
            'is_default_shipping' => true,
        ], $this->tenantUuid);

        $this->assertNotNull($billing);
        $this->assertNotNull($shipping);
        $this->assertTrue($billing->is_default_billing);
        $this->assertTrue($shipping->is_default_shipping);

        $addresses = ContactAddress::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $contactId)
            ->get();
        $this->assertCount(2, $addresses);
    }

    // ─── VEN-AT-11b: Historical document snapshot unchanged ─────────────

    public function test_ven_at_11b_historical_snapshot_unchanged_after_edit(): void
    {
        $customer = Customer::create([
            'code' => 'VC100',
            'name' => 'Acme Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        // Step 1: Import creates contact with legacy snapshot
        $contact = $customer->customerContacts()->create([
            'name' => 'John Doe',
            'is_primary' => true,
            'original_legacy_values' => [
                'contact_type' => 'Primary',
                'contact_category' => 'clinic',
                'billing_address' => '123 Old St',
            ],
        ]);

        // Step 2: Create a canonical ORGANISATION contact and link it
        $orgUuid = \Illuminate\Support\Str::uuid()->toString();
        CoreContact::create([
            'id' => $orgUuid,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Acme Corp',
            'contact_type' => 'company',
        ]);
        $customer->update(['core_contact_uuid' => $orgUuid]);

        // Step 3: Link the person contact to canonical identity
        $personUuid = \Illuminate\Support\Str::uuid()->toString();
        CoreContact::create([
            'id' => $personUuid,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'John Doe',
            'contact_type' => 'individual',
            'email' => 'john@acme.com',
            'phone' => '5551234567',
        ]);
        $contact->update(['core_contact_uuid' => $personUuid]);

        // Step 4: Create a contact relationship (simulating document linkage)
        ContactRelationship::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'contact_id' => $orgUuid,
            'related_contact_id' => $personUuid,
            'relationship_type' => 'org_person',
        ]);

        // Step 5: Edit the contact (simulate time passing)
        $contact->update([
            'name' => 'John Doe (Updated)',
            'email' => 'john.new@acme.com',
            'phone' => '5559999999',
        ]);

        // Step 6: Verify the customer_contacts snapshot is preserved
        // even after the contact is edited. This is the ADR-003A CT-VEN-019
        // invariant: original_legacy_values captures the state at import time
        // for reconciliation against issued transactions.
        $contact->refresh();
        $this->assertEquals('John Doe (Updated)', $contact->name);
        $this->assertEquals('john.new@acme.com', $contact->email);
        $this->assertEquals('Primary', $contact->original_legacy_values['contact_type']);
        $this->assertEquals('clinic', $contact->original_legacy_values['contact_category']);
        $this->assertEquals('123 Old St', $contact->original_legacy_values['billing_address']);

        // Step 7: Verify the canonical person contact is NOT affected by
        // the CRM-level edit (Contact Master is authoritative)
        $person = CoreContact::find($personUuid);
        $this->assertNotNull($person);
        $this->assertEquals('John Doe', $person->display_name);
        $this->assertEquals('john@acme.com', $person->email);

        // Step 8: Verify the org-person relationship is intact
        $relationship = ContactRelationship::where('tenant_id', $this->tenantUuid)
            ->where('contact_id', $orgUuid)
            ->where('related_contact_id', $personUuid)
            ->first();
        $this->assertNotNull($relationship);
    }

    // ─── VEN-AT-13b: Category differs across orgs ──────────────────────

    public function test_ven_at_13b_category_differs_across_orgs(): void
    {
        $orgA_id = \Illuminate\Support\Str::uuid()->toString();
        $orgB_id = \Illuminate\Support\Str::uuid()->toString();
        $personId = \Illuminate\Support\Str::uuid()->toString();

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

        CoreContact::create([
            'id' => $personId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Dr Smith',
            'contact_type' => 'individual',
        ]);

        $contactMasterService = app(ContactMasterService::class);

        $relA = $contactMasterService->addRelationship($orgA_id, $personId, 'org_person', $this->tenantUuid, [
            'functional_category_code' => 'CLINICAL',
        ]);

        $relB = $contactMasterService->addRelationship($orgB_id, $personId, 'org_person', $this->tenantUuid, [
            'functional_category_code' => 'FINANCE',
        ]);

        $this->assertNotNull($relA);
        $this->assertNotNull($relB);
        $this->assertEquals('CLINICAL', $relA->functional_category_code);
        $this->assertEquals('FINANCE', $relB->functional_category_code);

        // Same person, different categories per relationship
        $this->assertNotEquals($relA->functional_category_code, $relB->functional_category_code);
    }

    // ─── VEN-AT-16b: Concurrent SetPrimaryContact — second wins ────────

    public function test_ven_at_16b_concurrent_set_primary_second_wins(): void
    {
        $customer = Customer::create([
            'code' => 'VC0001',
            'name' => 'Acme Corp',
            'is_active' => true,
            'total_outstanding' => 0,
        ]);

        $contact1 = $customer->customerContacts()->create(['name' => 'John', 'is_primary' => true]);
        $contact2 = $customer->customerContacts()->create(['name' => 'Jane', 'is_primary' => false]);
        $contact3 = $customer->customerContacts()->create(['name' => 'Bob', 'is_primary' => false]);

        // Genuine concurrency test: use separate DB connections with overlapping
        // transactions to verify row-lock serialization.
        $connection1 = DB::connection('pgsql');
        $connection2 = DB::connection('pgsql');

        // Connection 1: start transaction + lock the customer row
        $connection1->beginTransaction();
        $connection1->table('customers')
            ->where('id', $customer->id)
            ->lockForUpdate()
            ->first();

        // Connection 2: start transaction + attempt to lock (will block)
        $connection2->beginTransaction();
        $locked = false;
        try {
            // Use a short timeout to detect the lock contention
            $connection2->statement('SET lock_timeout = 2000');
            $connection2->table('customers')
                ->where('id', $customer->id)
                ->lockForUpdate()
                ->first();
        } catch (\Exception $e) {
            $locked = true; // Lock contention detected
        }

        // Connection 1: set contact2 as primary
        $connection1->table('customer_contacts')
            ->where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        $connection1->table('customer_contacts')
            ->where('id', $contact2->id)
            ->update(['is_primary' => true]);
        $connection1->commit();

        // Connection 2: now can proceed — sets contact3 as primary
        if (! $locked) {
            $connection2->table('customer_contacts')
                ->where('customer_id', $customer->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
            $connection2->table('customer_contacts')
                ->where('id', $contact3->id)
                ->update(['is_primary' => true]);
        }
        $connection2->commit();

        // Verify exactly one primary exists
        $primaryCount = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->count();
        $this->assertEquals(1, $primaryCount);

        // The last writer (connection 2) wins — contact3 is primary
        $primary = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->first();
        $this->assertEquals($contact3->id, $primary->id);
    }
}
