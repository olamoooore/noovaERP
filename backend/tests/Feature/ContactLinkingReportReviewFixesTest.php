<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 3, second hostile review of the linking plan
 * (2026-10-06). The linking report must predict what a linking run would
 * really do:
 *
 *  A. a customer MERGED into another (POST /customers/{id}/merge) keeps its
 *     link to the losing Contact Master record by design — never "move it to
 *     the survivor" (two customers would then overwrite one organisation);
 *  B. category/type codes are checked the way the Contact Master checks them
 *     (ContactTaxonomyService::assertValidCode: seeded or in the tenant's
 *     vocabulary) — healthcare-only codes are invalid without
 *     `contacts.healthcare_taxonomy`;
 *  C. the run is sequential: a person created earlier in the batch is a
 *     match target for later contacts (tier 2 includes the office phone,
 *     compared exactly as the live matcher compares it);
 *  D. every row carries a fingerprint of its source data, so a review
 *     decision can be refused when the row changed after review;
 *  E. a contact of an INDIVIDUAL customer that matches the customer's own
 *     person record would be linked to the customer itself;
 *  F. customers sharing a company registration number are possible
 *     duplicates of each other.
 *
 * Linked data goes through the real HTTP routes; legacy unlinked rows are
 * inserted directly (the current API can no longer produce them).
 */
class ContactLinkingReportReviewFixesTest extends TenantTestCase
{
    private string $tenantId;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Linking Review Fixes Tenant',
            'email' => $this->tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin, 'sanctum');

        // A generic (non-healthcare) tenant.
        Setting::setValue(ContactTaxonomyRegistry::HEALTHCARE_SETTING_KEY, false);

        $this->reportPath = tempnam(sys_get_temp_dir(), 'linking-fixes-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_merged_customer_keeps_its_link_and_a_contact_master_merge_still_needs_review(): void
    {
        $survivor = $this->linkedCustomer('Survivor Medical');
        $merged = $this->linkedCustomer('Survivor Medical (old)');
        $this->postJson("/api/v1/customers/{$survivor['id']}/merge", ['merged_customer_id' => $merged['id'], 'reason' => 'duplicate'])
            ->assertSuccessful();
        $this->assertTrue((bool) CoreContact::whereKey($merged['org'])->value('is_duplicate'), 'Precondition: the merge marked the losing record.');

        // Only the Contact Master records were merged; both customers active.
        $a = $this->linkedCustomer('Alpha Clinic');
        $b = $this->linkedCustomer('Beta Clinic');
        DB::table('core_contacts')->where('id', $a['org'])->update(['is_duplicate' => true, 'merged_into_id' => $b['org']]);

        $report = $this->runReport();
        $orgs = collect($report['organisations'])->keyBy('customer_id');

        $this->assertSame('keep_merged_customer_link', $orgs[$merged['id']]['action'], 'A merged customer keeps its link by design.');
        $this->assertSame($survivor['id'], $orgs[$merged['id']]['merged_into_customer_id']);

        $this->assertSame(['needs_review', 'linked_to_merged_duplicate'], [$orgs[$a['id']]['action'], $orgs[$a['id']]['reason']]);
    }

    public function test_codes_are_checked_against_the_tenant_vocabulary(): void
    {
        $fresh = $this->unlinkedCustomer('Generic Trading');
        $clinical = $this->legacyContact($fresh->id, 'Clinic Liaison', ['contact_type' => 'Clinical', 'email' => 'liaison@example.test']);
        $billing = $this->legacyContact($fresh->id, 'Billing Clerk', ['contact_type' => 'Billing', 'email' => 'billing@example.test']);

        $people = collect($this->runReport()['people'])->keyBy('customer_contact_id');

        $this->assertSame('needs_review', $people[$clinical]['action']);
        $this->assertContains('CLINICAL_CONTACT', $people[$clinical]['flags']['taxonomy_code_not_valid_for_tenant']);
        $this->assertSame('create_person', $people[$billing]['action'], 'A code in the generic vocabulary is fine.');
    }

    public function test_batch_is_simulated_in_run_order(): void
    {
        $fresh = $this->unlinkedCustomer('Switchboard Sdn Bhd');
        // Same office phone (stored as the matcher compares it), different people.
        $first = $this->legacyContact($fresh->id, 'Aminah', ['phone' => '0312345678']);
        $second = $this->legacyContact($fresh->id, 'Boon Hock', ['phone' => '0312345678']);
        // Same name and email at two companies: the same human, linked once.
        $other = $this->unlinkedCustomer('Second Branch');
        $sameA = $this->legacyContact($fresh->id, 'Dr Wong', ['email' => 'wong@example.test']);
        $sameB = $this->legacyContact($other->id, 'Dr Wong', ['email' => 'WONG@example.test']);

        $people = collect($this->runReport()['people'])->keyBy('customer_contact_id');

        $this->assertSame('create_person', $people[$first]['action']);
        $this->assertSame('needs_review', $people[$second]['action'], 'The live run would link Boon Hock to Aminah through the office phone.');
        $this->assertSame('auto_link_existing_person', $people[$second]['predicted_action']);
        $this->assertSame($first, $people[$second]['flags']['matches_person_created_earlier_in_batch']);
        $this->assertTrue($people[$second]['flags']['name_differs']);

        $this->assertSame('create_person', $people[$sameA]['action']);
        $this->assertSame('auto_link_existing_person', $people[$sameB]['action']);
        $this->assertSame('batch:'.$sameA, $people[$sameB]['person_id']);
    }

    public function test_rows_carry_a_fingerprint_that_changes_with_the_source(): void
    {
        $fresh = $this->unlinkedCustomer('Print Shop');
        $contact = $this->legacyContact($fresh->id, 'Pat', ['email' => 'pat@example.test']);

        $first = $this->runReport();
        $orgPrint = collect($first['organisations'])->firstWhere('customer_id', $fresh->id)['fingerprint'];
        $personPrint = collect($first['people'])->firstWhere('customer_contact_id', $contact)['fingerprint'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $orgPrint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $personPrint);

        $again = $this->runReport();
        $this->assertSame($personPrint, collect($again['people'])->firstWhere('customer_contact_id', $contact)['fingerprint'], 'Unchanged data, same fingerprint.');

        DB::table('customer_contacts')->where('id', $contact)->update(['email' => 'pat.new@example.test']);
        $changed = $this->runReport();
        $this->assertNotSame($personPrint, collect($changed['people'])->firstWhere('customer_contact_id', $contact)['fingerprint']);
        $this->assertSame($orgPrint, collect($changed['organisations'])->firstWhere('customer_id', $fresh->id)['fingerprint']);
    }

    public function test_individual_customer_contact_matching_the_customer_itself_is_flagged(): void
    {
        $person = $this->postJson('/api/v1/customers', ['name' => 'Lee Mei', 'type' => 'individual', 'email' => 'lee.mei@example.test'])->assertCreated();
        $customerId = (int) $person->json('id');
        $self = $this->legacyContact($customerId, 'Lee Mei', ['email' => 'lee.mei@example.test']);

        $people = collect($this->runReport()['people'])->keyBy('customer_contact_id');

        $this->assertSame('needs_review', $people[$self]['action']);
        $this->assertTrue($people[$self]['flags']['would_link_to_customer_itself']);
    }

    public function test_shared_company_registration_number_is_a_possible_duplicate(): void
    {
        $linked = $this->postJson('/api/v1/customers', ['name' => 'Tan Holdings Sdn Bhd', 'type' => 'company', 'company_registration_number' => '201901012345'])->assertCreated();
        $linkedId = (int) $linked->json('id');
        $twin = $this->unlinkedCustomer('Tan Holdings', ['company_registration_number' => '2019-0101 2345']);
        $batchA = $this->unlinkedCustomer('Lim Trading', ['company_registration_number' => 'SA0123456-X']);
        $batchB = $this->unlinkedCustomer('Lim Trading Co', ['company_registration_number' => 'sa0123456x']);

        $orgs = collect($this->runReport()['organisations'])->keyBy('customer_id');

        $this->assertSame(['needs_review', 'possible_existing_organisation'], [$orgs[$twin->id]['action'], $orgs[$twin->id]['reason']]);
        $candidate = collect($orgs[$twin->id]['candidates'])->firstWhere('contact_id', Customer::findOrFail($linkedId)->core_contact_uuid);
        $this->assertNotNull($candidate);
        $this->assertContains('registration_number', $candidate['matched_on']);

        foreach ([$batchA, $batchB] as $customer) {
            $this->assertSame('needs_review', $orgs[$customer->id]['action']);
            $this->assertEqualsCanonicalizing([$batchA->id, $batchB->id], $orgs[$customer->id]['flags']['same_registration_number_in_batch']);
        }
    }

    /** @return array<string, mixed> */
    private function runReport(): array
    {
        $before = $this->tableCounts();
        $this->artisan('contacts:linking-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        return json_decode((string) file_get_contents($this->reportPath), true)[0];
    }

    /** @return array{id: int, org: string} */
    private function linkedCustomer(string $name): array
    {
        $id = (int) $this->postJson('/api/v1/customers', ['name' => $name, 'type' => 'company'])->assertCreated()->json('id');

        return ['id' => $id, 'org' => (string) Customer::findOrFail($id)->core_contact_uuid];
    }

    private function unlinkedCustomer(string $name, array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge(
            ['name' => $name, 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null],
            $attributes
        ));
    }

    private function legacyContact(int $customerId, string $name, array $attributes = []): int
    {
        return (int) DB::table('customer_contacts')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'name' => $name,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customers', 'customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles', 'contact_taxonomy'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['linked_customers'] = DB::table('customers')->whereNotNull('core_contact_uuid')->count();
        $counts['linked_contacts'] = DB::table('customer_contacts')->whereNotNull('core_contact_uuid')->count();

        return $counts;
    }
}
