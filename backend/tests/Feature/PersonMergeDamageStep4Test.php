<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 4 (merge repair plan): what the person merge damage
 * report must show before any repair can be planned. Until fix 9e389eb the
 * matcher linked a contact with no email/mobile to an arbitrary person.
 *
 *  A. damage on a person linked from ONE contact only (the person was made
 *     elsewhere — Contact Master, import) — the report only looked at people
 *     linked from several contacts;
 *  B. a link sharing an email/mobile/phone with the person but under another
 *     name is normal tier-1 matching (#1012), not this bug: reported as
 *     `shared_identifier_different_name`, apart from bug damage;
 *  C. per link: the organisation, and whether a correct (`ok`) link at the
 *     SAME organisation shares the org-person relationship — then category,
 *     roles and site cannot be split mechanically;
 *  D. documents that point at the damaged person (invoice doctor, requestor,
 *     buyer, billing and shipping contact; quotation billing/shipping) by
 *     customer, issued vs draft — invoices print these names LIVE;
 *  E. person classifications not explained by any correct link.
 *
 * The report must write nothing.
 */
class PersonMergeDamageStep4Test extends TenantTestCase
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
            'name' => 'Merge Repair Tenant',
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

        Setting::setValue(ContactTaxonomyRegistry::HEALTHCARE_SETTING_KEY, true);

        $this->reportPath = tempnam(sys_get_temp_dir(), 'merge-repair-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_shows_what_a_repair_needs_and_writes_nothing(): void
    {
        [$hospital, $hospitalOrg] = $this->createCustomer('Acme Hospital');
        [$clinic, $clinicOrg] = $this->createCustomer('Bayu Clinic');

        $siti = $this->addContact($hospital, ['name' => 'Siti', 'email' => 'siti@acme.test']);
        $sitiPerson = $siti['core_contact_uuid'];

        // Wrongly merged at another company (own relationship) and at the
        // same company (the relationship is Siti's).
        $ali = $this->wronglyMerged($clinic, $clinicOrg, 'Ali', $sitiPerson);
        $lim = $this->wronglyMerged($hospital, null, 'Nurse Lim', $sitiPerson, ['contact_type' => 'Nurse']);
        // Nurse Lim's save gave Siti's person a NURSE classification.
        app(ContactMasterService::class)->assignClassification($sitiPerson, 'NURSE', $this->tenantId);

        // Normal tier-1 matching on a shared mailbox (#1012), not this bug.
        $sharedBox = $this->wronglyMerged($clinic, null, 'Ali Bin Abu', $sitiPerson, ['email' => 'SITI@acme.test']);

        // A: a person made in the Contact Master, linked from ONE wrong contact.
        $whoContact = app(ContactMasterService::class)->create(['contact_type' => 'individual', 'display_name' => 'Dr Who', 'email' => 'who@example.test', 'source' => 'manual'], $this->tenantId);
        $whoId = (string) $whoContact->id;
        $zara = $this->wronglyMerged($clinic, $clinicOrg, 'Zara', $whoId);

        // D: documents pointing at Siti's person.
        Invoice::factory()->create(['customer_id' => $clinic, 'status' => 'posted', 'doctor_contact_id' => $sitiPerson]);
        Invoice::factory()->create(['customer_id' => $hospital, 'status' => 'draft', 'requestor_contact_id' => $sitiPerson]);

        $before = $this->tableCounts();
        $this->artisan('contacts:person-merge-damage-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $people = collect($report['people'])->keyBy('person_id');

        // A
        $this->assertArrayHasKey($whoId, $people->all(), 'Damage on a person with a single link is reported.');
        $this->assertSame('suspect', $people[$whoId]['severity']);
        $this->assertNull($people[$whoId]['created_from_customer_contact_id']);
        $this->assertSame('suspect', collect($people[$whoId]['links'])->firstWhere('customer_contact_id', $zara)['status']);

        $damaged = $people[$sitiPerson];
        $links = collect($damaged['links'])->keyBy('customer_contact_id');

        // B
        $this->assertSame('shared_identifier_different_name', $links[$sharedBox]['status']);
        $this->assertSame('suspect', $damaged['severity'], 'Bug damage outranks a shared identifier.');

        // C
        $this->assertSame($clinicOrg, $links[$ali]['organisation_id']);
        $this->assertFalse($links[$ali]['shares_relationship_with_ok_link']);
        $this->assertSame($hospitalOrg, $links[$lim]['organisation_id']);
        $this->assertTrue($links[$lim]['shares_relationship_with_ok_link'], "Nurse Lim's relationship is Siti's.");

        // D
        $documents = collect($damaged['documents']);
        $issued = $documents->first(fn ($d) => $d['table'] === 'invoices' && $d['column'] === 'doctor_contact_id' && $d['customer_id'] === $clinic);
        $this->assertSame([1, 0, false], [$issued['issued'], $issued['draft'], $issued['customer_has_ok_link']]);
        $draft = $documents->first(fn ($d) => $d['column'] === 'requestor_contact_id' && $d['customer_id'] === $hospital);
        $this->assertSame([0, 1, true], [$draft['issued'], $draft['draft'], $draft['customer_has_ok_link']]);

        // E
        $this->assertSame(['NURSE'], $damaged['classifications']);
        $this->assertSame(['NURSE'], $damaged['classifications_not_explained_by_ok_links']);

        // F: a fingerprint per person, so a review decision is refused once
        // the person, a link or a relationship changed.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $damaged['fingerprint']);
        DB::table('customer_contacts')->where('id', $ali)->update(['name' => 'Ali Renamed']);
        $this->artisan('contacts:person-merge-damage-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])->assertExitCode(0);
        $again = collect(json_decode((string) file_get_contents($this->reportPath), true)[0]['people'])->keyBy('person_id');
        $this->assertNotSame($damaged['fingerprint'], $again[$sitiPerson]['fingerprint']);
        $this->assertSame($people[$whoId]['fingerprint'], $again[$whoId]['fingerprint']);

        $this->assertSame(2, $report['summary']['people_with_suspect_links']);
        $this->assertSame(1, $report['summary']['shared_identifier_links']);
        $this->assertSame(1, $report['summary']['issued_documents']);
    }

    /**
     * Second hostile review of the repair plan (2026-10-06): the report must
     * also show
     *  G. data the person OWNS (identifiers, methods, addresses, profiles…)
     *     — users may have added a wrongly merged contact's data to it;
     *  H. doctors referenced inside an invoice's `case_information` JSON;
     *  I. each company relationship's current values next to what each link
     *     at that company implies (category, roles, department);
     *  J. soft-deleted damaged people (step 3 L3 may restore them);
     *  K. a fingerprint that also covers roles, documents and owned data;
     *  L. a size flag for people with very many links (one transaction each).
     */
    public function test_report_covers_owned_data_case_documents_relationship_values_and_deleted_people(): void
    {
        [$hospital, $hospitalOrg] = $this->createCustomer('Acme Hospital');
        $siti = $this->addContact($hospital, ['name' => 'Siti', 'email' => 'siti@acme.test', 'contact_type' => 'Billing']);
        $sitiPerson = $siti['core_contact_uuid'];
        $lim = $this->wronglyMerged($hospital, null, 'Nurse Lim', $sitiPerson, ['contact_type' => 'Nurse', 'department_name' => 'Ward 3']);

        $master = app(ContactMasterService::class);
        // G: someone added an identifier to "Siti" in the Contact Master.
        $master->addIdentifier($sitiPerson, 'staff_no', 'S-001', $this->tenantId);

        // H: a doctor reference inside the case information of an issued invoice.
        Invoice::factory()->create(['customer_id' => $hospital, 'status' => 'posted', 'case_information' => [['case_number' => 'C-1', 'doctor_contact_id' => $sitiPerson]]]);

        // J: a damaged person that was later soft-deleted.
        $gone = $master->create(['contact_type' => 'individual', 'display_name' => 'Gone Doc', 'source' => 'manual'], $this->tenantId);
        $goneId = (string) $gone->id;
        $this->wronglyMerged($hospital, null, 'Porter Ravi', $goneId);
        $gone->delete();

        // L: a person carrying very many wrong links (an import of
        // identifier-less contacts).
        $bulk = $master->create(['contact_type' => 'individual', 'display_name' => 'Bulk Target', 'source' => 'manual'], $this->tenantId);
        $bulkId = (string) $bulk->id;
        for ($i = 1; $i <= 51; $i++) {
            $this->wronglyMerged($hospital, null, 'Imported '.$i, $bulkId);
        }

        $before = $this->tableCounts();
        $this->artisan('contacts:person-merge-damage-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');
        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $people = collect($report['people'])->keyBy('person_id');
        $damaged = $people[$sitiPerson];

        // G
        $this->assertSame(1, $damaged['owned_data']['contact_identifiers']);
        $this->assertSame(0, $damaged['owned_data']['pos_loyalty_profiles']);

        // H
        $case = collect($damaged['documents'])->first(fn ($d) => $d['column'] === 'case_information');
        $this->assertNotNull($case, 'Person references inside case_information are documents too.');
        $this->assertSame(1, $case['issued']);

        // I
        $relationship = collect($damaged['relationships'])->firstWhere('organisation_id', $hospitalOrg);
        $this->assertNotNull($relationship);
        $this->assertArrayHasKey('functional_category_code', $relationship);
        $this->assertArrayHasKey('roles', $relationship);
        $this->assertArrayHasKey('site_address_id', $relationship);
        $limLink = collect($damaged['links'])->firstWhere('customer_contact_id', $lim);
        $this->assertSame('NURSE', $limLink['implied_classification']);
        $this->assertSame('Ward 3', $limLink['department_name']);
        $this->assertArrayHasKey('implied_functional_category', $limLink);
        $this->assertArrayHasKey('implied_roles', $limLink);

        // J
        $this->assertArrayHasKey($goneId, $people->all(), 'A soft-deleted damaged person is reported.');
        $this->assertTrue($people[$goneId]['person_deleted']);

        // K
        $print = $damaged['fingerprint'];
        $master->addIdentifier($sitiPerson, 'staff_no', 'S-002', $this->tenantId);
        $this->artisan('contacts:person-merge-damage-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])->assertExitCode(0);
        $again = collect(json_decode((string) file_get_contents($this->reportPath), true)[0]['people'])->keyBy('person_id');
        $this->assertNotSame($print, $again[$sitiPerson]['fingerprint'], 'Owned data is part of the fingerprint.');

        // L
        $this->assertTrue($people[$bulkId]['oversized']);
        $this->assertSame(51, $people[$bulkId]['link_count']);
        $this->assertFalse($damaged['oversized']);
    }

    /** @return array{0: int, 1: string} */
    private function createCustomer(string $name): array
    {
        $created = $this->postJson('/api/v1/customers', ['name' => $name, 'type' => 'company'])->assertCreated();

        return [(int) $created->json('id'), $created->json('core_contact_uuid')];
    }

    private function addContact(int $customerId, array $payload): array
    {
        $json = $this->postJson("/api/v1/customers/{$customerId}/contacts", $payload)->assertCreated()->json();

        return ['id' => (int) $json['id'], 'core_contact_uuid' => $json['core_contact_uuid']];
    }

    /** What the bug left behind: a contact on someone else's person (+ the relationship the sync made). */
    private function wronglyMerged(int $customerId, ?string $orgId, string $name, string $personId, array $attributes = []): int
    {
        $id = (int) DB::table('customer_contacts')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'name' => $name,
            'core_contact_uuid' => $personId,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        if ($orgId) {
            DB::table('contact_relationships')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'contact_id' => $orgId,
                'related_contact_id' => $personId,
                'relationship_type' => 'org_person',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles', 'contact_group_members', 'invoices'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
