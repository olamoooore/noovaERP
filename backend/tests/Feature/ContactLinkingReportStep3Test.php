<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 3 (linking execution plan): cases the linking report must
 * surface before any linking run, because the live linking code
 * (CustomerContactSyncService) would silently do the wrong thing:
 *
 *  - a link to a SOFT-DELETED Contact Master record: find() does not see it,
 *    so ensureOrganisationContact()/resolvePerson() create a NEW record and
 *    orphan the old one with its addresses, relationships and sites;
 *  - a link to a MERGED DUPLICATE (is_duplicate, merged_into_id): find() does
 *    see it, so the customer keeps syncing into the losing record instead of
 *    the survivor — the report skipped these as "linked";
 *  - an auto-link onto a person already damaged by the matcher bug fixed in
 *    9e389eb (PersonMergeDamagePlanner: suspect/unverifiable links) would add
 *    one more contact to a wrongly merged person.
 *
 * A link to a record that does not exist at all cannot occur: a foreign key
 * (2026_08_13_000002) guards both link columns. The report must write
 * nothing.
 */
class ContactLinkingReportStep3Test extends TenantTestCase
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
            'name' => 'Linking Step 3 Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'linking-step3-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_surfaces_deleted_merged_and_damaged_link_targets_and_writes_nothing(): void
    {
        // Organisation whose Contact Master record was soft-deleted.
        $deletedOrg = $this->linkedCustomer('Deleted Org Clinic');
        CoreContact::whereKey($deletedOrg['org'])->first()->delete();

        // Organisation merged into a survivor.
        $loser = $this->linkedCustomer('Loser Clinic');
        $survivor = $this->linkedCustomer('Survivor Clinic');
        DB::table('core_contacts')->where('id', $loser['org'])->update(['is_duplicate' => true, 'merged_into_id' => $survivor['org']]);

        // People: one linked to a soft-deleted person, one to a merged duplicate.
        $deletedPerson = $this->postJson("/api/v1/customers/{$survivor['id']}/contacts", ['name' => 'Gone Person', 'email' => 'gone@example.test'])->assertCreated();
        CoreContact::whereKey($deletedPerson->json('core_contact_uuid'))->first()->delete();

        $dupPerson = $this->postJson("/api/v1/customers/{$survivor['id']}/contacts", ['name' => 'Dup Person', 'email' => 'dup@example.test'])->assertCreated();
        $keptPerson = $this->postJson("/api/v1/customers/{$survivor['id']}/contacts", ['name' => 'Kept Person', 'email' => 'kept@example.test'])->assertCreated();
        DB::table('core_contacts')->where('id', $dupPerson->json('core_contact_uuid'))->update(['is_duplicate' => true, 'merged_into_id' => $keptPerson->json('core_contact_uuid')]);

        // A person wrongly merged by the old matcher: two contacts with
        // different names and no shared identifier point at one person.
        $damaged = $this->postJson("/api/v1/customers/{$survivor['id']}/contacts", ['name' => 'Dr Tan', 'email' => 'tan@example.test'])->assertCreated();
        $this->legacyContact($survivor['id'], 'Nurse Lim', ['core_contact_uuid' => $damaged->json('core_contact_uuid')]);
        // An unlinked contact whose email matches the damaged person (tier 1).
        $wouldJoin = $this->legacyContact($survivor['id'], 'Dr Tan', ['email' => 'tan@example.test']);

        $before = $this->tableCounts();

        $this->artisan('contacts:linking-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $orgs = collect($report['organisations'])->keyBy('customer_id');

        $this->assertSame(['needs_review', 'linked_contact_deleted'], [$orgs[$deletedOrg['id']]['action'], $orgs[$deletedOrg['id']]['reason']]);
        $this->assertSame($deletedOrg['org'], $orgs[$deletedOrg['id']]['linked_contact_id']);

        $this->assertSame(['needs_review', 'linked_to_merged_duplicate'], [$orgs[$loser['id']]['action'], $orgs[$loser['id']]['reason']]);
        $this->assertSame($survivor['org'], $orgs[$loser['id']]['merged_into_id']);

        $this->assertArrayNotHasKey($survivor['id'], $orgs->all(), 'A correctly linked customer is not planned.');

        $people = collect($report['people'])->keyBy('customer_contact_id');

        $gone = $people[(int) $deletedPerson->json('id')];
        $this->assertSame(['needs_review', 'linked_contact_deleted'], [$gone['action'], $gone['reason']]);

        $dup = $people[(int) $dupPerson->json('id')];
        $this->assertSame(['needs_review', 'linked_to_merged_duplicate'], [$dup['action'], $dup['reason']]);
        $this->assertSame($keptPerson->json('core_contact_uuid'), $dup['merged_into_id']);

        $join = $people[$wouldJoin];
        $this->assertSame('needs_review', $join['action']);
        $this->assertSame('auto_link_existing_person', $join['predicted_action']);
        $this->assertSame($damaged->json('core_contact_uuid'), $join['person_id']);
        $this->assertSame('suspect', $join['flags']['target_person_has_merge_damage']);

        $this->assertSame(1, $report['summary']['organisations']['needs_review:linked_contact_deleted']);
        $this->assertSame(1, $report['summary']['organisations']['needs_review:linked_to_merged_duplicate']);
    }

    /** @return array{id: int, org: string} */
    private function linkedCustomer(string $name): array
    {
        $response = $this->postJson('/api/v1/customers', ['name' => $name, 'type' => 'company'])->assertCreated();
        $id = (int) $response->json('id');

        return ['id' => $id, 'org' => (string) Customer::findOrFail($id)->core_contact_uuid];
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
        foreach (['customers', 'customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['linked_customers'] = DB::table('customers')->whereNotNull('core_contact_uuid')->count();
        $counts['linked_contacts'] = DB::table('customer_contacts')->whereNotNull('core_contact_uuid')->count();
        $counts['deleted_core_contacts'] = DB::table('core_contacts')->whereNotNull('deleted_at')->count();

        return $counts;
    }
}
