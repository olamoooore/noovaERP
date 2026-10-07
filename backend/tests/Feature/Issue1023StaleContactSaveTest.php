<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Tests\TenantTestCase;

/**
 * Issue #1023 (W6 Gate 2): a customer-contact save works from the contact
 * row it loaded at the start of the request. If a W6 repair or linking run
 * changes that row's person link in between, the save must follow the
 * CURRENT link, not the stale one.
 *
 * The concurrent run is simulated through the real update route: the moment
 * the controller has loaded the contact (Eloquent `retrieved`), the run's
 * change is written straight to the database, so the request continues with
 * the stale in-memory row — exactly the interleaving of two connections.
 */
class Issue1023StaleContactSaveTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Stale Save Tenant',
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
    }

    /**
     * Race 1 (merge repair): the repair moves the contact from the wrongly
     * shared person P to a new person Pk, together with its company
     * relationship. A save that loaded the contact before the repair must
     * not recreate P's relationship or give P the contact's classification.
     */
    public function test_a_save_that_loaded_the_contact_before_a_repair_does_not_reattach_the_old_person(): void
    {
        [$clinic] = $this->createCustomer('Bayu Clinic');
        [$hospital, $hospitalOrg] = $this->createCustomer('Acme Hospital');
        $sitiPerson = $this->postJson("/api/v1/customers/{$clinic}/contacts", ['name' => 'Siti', 'email' => 'siti@bayu.test'])->assertCreated()->json('core_contact_uuid');

        // What the matcher bug left: Ali at the hospital on Siti's person P.
        $ali = (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $hospital, 'name' => 'Ali', 'core_contact_uuid' => $sitiPerson,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->relationship($hospitalOrg, $sitiPerson);
        $classificationsOfP = DB::table('contact_group_members')->where('contact_id', $sitiPerson)->count();

        $repairedPerson = null;
        $this->whenLoaded($ali, function () use ($ali, $hospitalOrg, $sitiPerson, &$repairedPerson) {
            // The repair: a new person Pk for Ali; link and relationship move.
            $repairedPerson = (string) app(ContactMasterService::class)->create(['contact_type' => 'individual', 'display_name' => 'Ali'], $this->tenantId)->id;
            DB::table('customer_contacts')->where('id', $ali)->update(['core_contact_uuid' => $repairedPerson]);
            DB::table('contact_relationships')
                ->where('contact_id', $hospitalOrg)->where('related_contact_id', $sitiPerson)->where('relationship_type', 'org_person')
                ->update(['related_contact_id' => $repairedPerson]);
        });

        $this->putJson("/api/v1/customers/{$hospital}/contacts/{$ali}", [
            'name' => 'Ali', 'job_title' => 'Surgeon', 'classification_code' => 'DOCTOR',
        ])->assertSuccessful();

        $this->assertNotNull($repairedPerson, 'The simulated repair ran during the request.');
        $this->assertSame($repairedPerson, DB::table('customer_contacts')->where('id', $ali)->value('core_contact_uuid'), 'The contact stays on the repaired person.');
        $this->assertFalse(
            DB::table('contact_relationships')->where('contact_id', $hospitalOrg)->where('related_contact_id', $sitiPerson)->where('relationship_type', 'org_person')->exists(),
            "The stale save must not recreate the old person's relationship at the hospital."
        );
        $this->assertSame($classificationsOfP, DB::table('contact_group_members')->where('contact_id', $sitiPerson)->count(), "The old person must not get the contact's classification.");
        $this->assertTrue(
            DB::table('contact_relationships')->where('contact_id', $hospitalOrg)->where('related_contact_id', $repairedPerson)->where('relationship_type', 'org_person')->exists(),
            'The edit lands on the repaired person.'
        );
    }

    /**
     * Race 2 (linking): the linking run links a previously unlinked contact
     * to person X. A save that loaded the contact while it was unlinked must
     * keep the run's link and must not create another person.
     */
    public function test_a_save_that_loaded_the_contact_unlinked_keeps_the_link_the_run_made(): void
    {
        [$clinic, $clinicOrg] = $this->createCustomer('Bayu Clinic');
        $tan = (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $clinic, 'name' => 'Tan', 'core_contact_uuid' => null,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $runPerson = null;
        $this->whenLoaded($tan, function () use ($tan, $clinicOrg, &$runPerson) {
            // The linking run: person X for Tan, linked, with its relationship.
            $runPerson = (string) app(ContactMasterService::class)->create(['contact_type' => 'individual', 'display_name' => 'Tan'], $this->tenantId)->id;
            DB::table('customer_contacts')->where('id', $tan)->update(['core_contact_uuid' => $runPerson]);
            $this->relationship($clinicOrg, $runPerson);
        });
        $peopleBefore = DB::table('core_contacts')->where('contact_type', 'individual')->count();

        $this->putJson("/api/v1/customers/{$clinic}/contacts/{$tan}", ['name' => 'Tan', 'job_title' => 'Nurse'])->assertSuccessful();

        $this->assertNotNull($runPerson, 'The simulated linking run ran during the request.');
        $this->assertSame($runPerson, DB::table('customer_contacts')->where('id', $tan)->value('core_contact_uuid'), "The stale save must keep the run's link.");
        $this->assertSame($peopleBefore + 1, DB::table('core_contacts')->where('contact_type', 'individual')->count(), 'Only the run created a person; the stale save created none.');
    }

    /**
     * Hostile review of the fix: the re-read must take a ROW LOCK (so a run
     * that locks the contact row is serialised with the save), not just read.
     *
     * Each test runs inside one uncommitted transaction (RefreshDatabase), so
     * a second connection cannot see the rows to contend for them. Instead
     * the lock is read from the row itself: `SELECT … FOR UPDATE` stamps the
     * locking transaction's id into the row's `xmax` without writing it.
     *
     * A lock taken by an earlier save in this test would stay on the row, so
     * the measured contact is never saved through the API before: its values
     * are copied from a probe contact settled through the API, and the row is
     * inserted directly (`xmax` 0). The measured save sends the same values,
     * so it writes nothing to the row (`updated_at` unchanged); nothing in
     * this flow writes a row that references the contact (which would take a
     * key-share lock), so a non-zero `xmax` afterwards is THIS save's lock.
     */
    public function test_the_save_locks_the_contact_row_without_writing_it(): void
    {
        [$clinic, $clinicOrg] = $this->createCustomer('Bayu Clinic');
        $payload = ['name' => 'Tan', 'email' => 'tan@bayu.test'];

        // Probe: what a settled contact with this payload looks like.
        $probeId = (int) $this->postJson("/api/v1/customers/{$clinic}/contacts", $payload)->assertCreated()->json('id');
        $this->putJson("/api/v1/customers/{$clinic}/contacts/{$probeId}", $payload)->assertSuccessful();
        $settled = (array) DB::table('customer_contacts')->where('id', $probeId)->first(['contact_category', 'contact_type', 'department_name']);

        // The measured contact: same values, never saved through the API.
        $person = (string) app(ContactMasterService::class)->create(['contact_type' => 'individual', 'display_name' => 'Tan', 'email' => 'tan@bayu.test'], $this->tenantId)->id;
        $this->relationship($clinicOrg, $person);
        $contactId = (int) DB::table('customer_contacts')->insertGetId($settled + $payload + [
            'customer_id' => $clinic, 'core_contact_uuid' => $person, 'is_primary' => false,
            'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
        $before = DB::selectOne('select updated_at, xmax::text as xmax from customer_contacts where id = ?', [$contactId]);
        $this->assertSame('0', $before->xmax, 'Precondition: no one has locked the measured row yet.');

        $this->putJson("/api/v1/customers/{$clinic}/contacts/{$contactId}", $payload)->assertSuccessful();

        $after = DB::selectOne('select updated_at, xmax::text as xmax, core_contact_uuid from customer_contacts where id = ?', [$contactId]);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at, 'Precondition: the save writes nothing to the contact row.');
        $this->assertSame($person, $after->core_contact_uuid);
        $this->assertNotSame('0', $after->xmax, 'The save locked the contact row (FOR UPDATE stamps xmax).');
    }

    /**
     * Hostile review of the fix: a contact soft-deleted between the load and
     * the re-read must keep its link. The default scope would hide the row,
     * read the link as null, and match or create another person for it.
     */
    public function test_a_contact_deleted_during_the_save_keeps_its_link(): void
    {
        [$clinic] = $this->createCustomer('Bayu Clinic');
        $created = $this->postJson("/api/v1/customers/{$clinic}/contacts", ['name' => 'Lim'])->assertCreated();
        $limId = (int) $created->json('id');
        $limPerson = (string) $created->json('core_contact_uuid');
        $peopleBefore = DB::table('core_contacts')->where('contact_type', 'individual')->count();

        $this->whenLoaded($limId, function () use ($limId) {
            DB::table('customer_contacts')->where('id', $limId)->update(['deleted_at' => now()]);
        });

        $this->putJson("/api/v1/customers/{$clinic}/contacts/{$limId}", ['name' => 'Lim', 'job_title' => 'Admin'])->assertSuccessful();

        $this->assertSame($limPerson, DB::table('customer_contacts')->where('id', $limId)->value('core_contact_uuid'), 'A contact deleted mid-save keeps its person link.');
        $this->assertSame($peopleBefore, DB::table('core_contacts')->where('contact_type', 'individual')->count(), 'No person is created for a deleted contact.');
    }

    /**
     * Hostile review of the follow-up: a contact HARD-deleted between the
     * load and the re-read has no row. The save must stop (404) instead of
     * reading the link as null, matching or creating a person for a row that
     * no longer exists, and writing nothing.
     */
    public function test_a_contact_hard_deleted_during_the_save_stops_the_save(): void
    {
        [$clinic] = $this->createCustomer('Bayu Clinic');
        $limId = (int) $this->postJson("/api/v1/customers/{$clinic}/contacts", ['name' => 'Lim'])->assertCreated()->json('id');
        $peopleBefore = DB::table('core_contacts')->where('contact_type', 'individual')->count();
        $relationshipsBefore = DB::table('contact_relationships')->count();

        $this->whenLoaded($limId, function () use ($limId) {
            DB::table('customer_contacts')->where('id', $limId)->delete();
        });

        $this->putJson("/api/v1/customers/{$clinic}/contacts/{$limId}", ['name' => 'Lim', 'job_title' => 'Admin'])->assertNotFound();

        $this->assertSame($peopleBefore, DB::table('core_contacts')->where('contact_type', 'individual')->count(), 'No person is created for a contact that no longer exists.');
        $this->assertSame($relationshipsBefore, DB::table('contact_relationships')->count(), 'No relationship is written for it either.');
    }

    /** Runs $change once, the first time the request loads this contact. */
    private function whenLoaded(int $contactId, \Closure $change): void
    {
        $fired = false;
        CustomerContact::retrieved(function (CustomerContact $contact) use ($contactId, $change, &$fired) {
            if (! $fired && (int) $contact->id === $contactId) {
                $fired = true;
                $change();
            }
        });
    }

    private function relationship(string $orgId, string $personId): void
    {
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

    /** @return array{0: int, 1: string} */
    private function createCustomer(string $name): array
    {
        $created = $this->postJson('/api/v1/customers', ['name' => $name, 'type' => 'company'])->assertCreated();

        return [(int) $created->json('id'), (string) $created->json('core_contact_uuid')];
    }
}
