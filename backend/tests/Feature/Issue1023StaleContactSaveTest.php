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
