<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #187 — contact/address ids on a medical case were only checked for
 * existence, never that they belong to the case's own customer, so a direct
 * API call could attribute a doctor/requestor/buyer to the wrong hospital.
 */
class HR187MedicalCaseContactScopingTest extends TenantTestCase
{
    private User $user;

    private Customer $hospitalA;

    private Customer $hospitalB;

    private int $contactA;

    private int $contactB;

    private int $addressB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '66666666-5555-4444-3333-222222222222',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['medical_cases', 'customers', 'customer_contacts', 'customer_addresses']);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->hospitalA = Customer::factory()->create();
        $this->hospitalB = Customer::factory()->create();

        $this->contactA = (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $this->hospitalA->id,
            'name' => 'Dr. A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->contactB = (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $this->hospitalB->id,
            'name' => 'Dr. B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addressB = (int) DB::table('customer_addresses')->insertGetId([
            'customer_id' => $this->hospitalB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->hospitalA->id,
            'doctor_contact_id' => $this->contactA,
        ], $overrides);
    }

    public function test_store_rejects_a_contact_from_another_customer(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases', $this->payload(['doctor_contact_id' => $this->contactB]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('doctor_contact_id');
    }

    public function test_store_rejects_a_requestor_or_buyer_from_another_customer(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases', $this->payload([
                'requestor_contact_id' => $this->contactB,
                'buyer_contact_id' => $this->contactB,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requestor_contact_id', 'buyer_contact_id']);
    }

    public function test_store_rejects_a_delivery_address_from_another_customer(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases', $this->payload([
                'delivery_address_id' => $this->addressB,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_address_id');
    }

    public function test_store_accepts_contacts_from_the_cases_own_customer(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases', $this->payload());

        $response->assertCreated();
        $this->assertSame($this->contactA, (int) $response->json('data.doctor_contact_id'));
    }

    public function test_update_rejects_a_contact_from_another_customer(): void
    {
        $case = MedicalCase::create([
            'customer_id' => $this->hospitalA->id,
            'doctor_contact_id' => $this->contactA,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/medical-cases/'.$case->id, [
                'buyer_contact_id' => $this->contactB,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('buyer_contact_id');
    }
}
