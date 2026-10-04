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
 * Issue #432 (CU4): customer edits never synced to the canonical Contact
 * Master contact, and individual customers were always created as company
 * contacts.
 *
 * Interim fix under test: every customer edit keeps the linked
 * `core_contacts` row in sync in the same transaction, and
 * `customers.type = individual` maps to a PERSON contact, not a company.
 */
class Issue432CustomerContactSyncTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 432 Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_customer_update_syncs_the_canonical_contact_fields(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Acme Holdings',
            'type' => 'company',
            'email' => 'old@acme.test',
            'phone' => '1234567',
            'website' => 'https://old.acme.test',
        ])->assertCreated();

        $customer = Customer::findOrFail($created->json('id'));
        $this->assertNotNull($customer->core_contact_uuid, 'Customer creation must link a canonical contact.');

        $contact = CoreContact::findOrFail($customer->core_contact_uuid);
        $this->assertSame('company', $contact->contact_type);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/customers/'.$customer->id, [
                'name' => 'Acme Holdings International',
                'email' => 'new@acme.test',
                'phone' => '7654321',
                'website' => 'https://new.acme.test',
            ])
            ->assertOk();

        $contact->refresh();

        $this->assertSame(
            'Acme Holdings International',
            $contact->display_name,
            'Contact Master display name must follow the customer edit.'
        );
        $this->assertSame('Acme Holdings International', $contact->company_name);
        $this->assertSame('new@acme.test', $contact->email, 'Contact Master email must follow the customer edit.');
        $this->assertSame('7654321', $contact->phone, 'Contact Master phone must follow the customer edit.');
        $this->assertSame('https://new.acme.test', $contact->website, 'Contact Master website must follow the customer edit.');
    }

    public function test_customer_update_clears_contact_fields_removed_from_the_customer(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Bruno Trading',
            'type' => 'company',
            'email' => 'sales@bruno.test',
            'website' => 'https://bruno.test',
        ])->assertCreated();

        $customer = Customer::findOrFail($created->json('id'));
        $contact = CoreContact::findOrFail($customer->core_contact_uuid);
        $this->assertSame('sales@bruno.test', $contact->email);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/customers/'.$customer->id, [
                'email' => null,
                'website' => null,
            ])
            ->assertOk();

        $contact->refresh();

        $this->assertNull($contact->email, 'Removing the customer email must clear the stale Contact Master email.');
        $this->assertNull($contact->website, 'Removing the customer website must clear the stale Contact Master website.');
    }

    public function test_individual_customer_maps_to_a_person_contact_and_stays_synced(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Jane Doe',
            'type' => 'individual',
            'email' => 'jane@example.test',
        ])->assertCreated();

        $customer = Customer::findOrFail($created->json('id'));
        $this->assertNotNull($customer->core_contact_uuid);

        $contact = CoreContact::findOrFail($customer->core_contact_uuid);
        $this->assertSame(
            'individual',
            $contact->contact_type,
            'An individual customer must map to a PERSON contact, not a company contact.'
        );

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/customers/'.$customer->id, [
                'name' => 'Jane A. Doe',
            ])
            ->assertOk();

        $contact->refresh();
        $this->assertSame('individual', $contact->contact_type);
        $this->assertSame('Jane A. Doe', $contact->display_name);
    }
}
