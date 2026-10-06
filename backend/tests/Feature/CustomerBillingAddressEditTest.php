<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W1: editing a customer's billing address must change the billing
 * address the customer record (and therefore new documents) actually uses.
 *
 * The customer form loads the billing address from the structured default
 * billing address (`addresses[]`) and the create path saves it there. The
 * edit path used to send only the legacy `billing_address` text column, which
 * never updated the structured record. Payloads below mirror exactly what the
 * frontend sends on create and edit (`customerFormToUpdatePayload`).
 */
class CustomerBillingAddressEditTest extends TenantTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Billing Address Edit Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_editing_the_billing_address_changes_the_customers_default_billing_address(): void
    {
        // Create: customerFormToCreatePayload sends the billing fields as one
        // default billing address record.
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Acme Clinic',
            'type' => 'company',
            'addresses' => [[
                'type' => 'billing',
                'street' => '1 Old Road',
                'street2' => '',
                'city' => 'Kuala Lumpur',
                'state' => 'WP',
                'zip' => '50000',
                'country' => 'MY',
                'is_default' => true,
            ]],
        ])->assertCreated();

        $customerId = $created->json('id');

        // Edit: customerFormToUpdatePayload sends the structured billing
        // fields and also flattens them into the legacy `billing_address`
        // text column.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/customers/'.$customerId, [
                'name' => 'Acme Clinic',
                'billing_address_record' => [
                    'street' => '9 New Avenue',
                    'street2' => '',
                    'city' => 'Petaling Jaya',
                    'state' => 'Selangor',
                    'zip' => '46000',
                    'country' => 'MY',
                ],
                'billing_address' => implode("\n", ['9 New Avenue', 'Petaling Jaya', 'Selangor', '46000', 'MY']),
            ])
            ->assertOk();

        $customer = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers/'.$customerId)
            ->assertOk()
            ->json();

        $billing = collect($customer['addresses'] ?? [])
            ->first(fn (array $address) => $address['type'] === 'billing' && $address['is_default']);

        $this->assertNotNull($billing, 'The customer must keep a default billing address.');
        $this->assertSame(
            '9 New Avenue',
            $billing['street'],
            'The default billing address (what the edit form and new invoices read) must reflect the edit.'
        );
        $this->assertSame('Petaling Jaya', $billing['city']);
    }
}
