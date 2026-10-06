<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerLoyaltyBalance;
use App\Models\CustomerLoyaltyProgram;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #440 (CU12): a new customer's loyalty balances must be initialised
 * inside the canonical customer-creation transaction, once per active
 * programme.
 *
 * Acceptance criteria under test, through the real router:
 *  1. POST /api/v1/customers creates one balance row per active programme —
 *     not just the first one returned by the query.
 *  2. Inactive programmes get no balance.
 *  3. A failure while initialising the balances rolls the customer back
 *     (no customer row without its balances).
 *  4. The single-programme path is unchanged.
 */
class Issue440CustomerLoyaltyInitialisationTest extends TenantTestCase
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
            'name' => 'Issue 440 Tenant',
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

        // The tenant migrations seed a default "Standard Loyalty Program"
        // (is_active = true). Deactivate it so each test controls exactly
        // which programmes are active.
        CustomerLoyaltyProgram::query()->update(['is_active' => false]);
    }

    private function program(string $name, bool $active = true): CustomerLoyaltyProgram
    {
        return CustomerLoyaltyProgram::create([
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function createCustomer(string $name, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => $name,
            'type' => 'company',
            'email' => $email,
        ]);
    }

    public function test_customer_store_creates_one_balance_for_every_active_program(): void
    {
        $first = $this->program('Main Loyalty Programme');
        $second = $this->program('Partners Loyalty Programme');
        $inactive = $this->program('Retired Loyalty Programme', active: false);

        $response = $this->createCustomer('Multi Loyalty Trading', 'multi.loyalty@example.test')
            ->assertCreated();

        $customerId = $response->json('id');

        $this->assertSame(
            2,
            CustomerLoyaltyBalance::where('customer_id', $customerId)->count(),
            'A new customer must get a balance for EVERY active programme, not just the first one.'
        );

        foreach ([$first->id, $second->id] as $programId) {
            $this->assertDatabaseHas('customer_loyalty_balances', [
                'customer_id' => $customerId,
                'program_id' => $programId,
                'points_balance' => 0,
            ]);
        }

        $this->assertDatabaseMissing('customer_loyalty_balances', [
            'customer_id' => $customerId,
            'program_id' => $inactive->id,
        ]);
    }

    public function test_single_active_program_path_is_unchanged(): void
    {
        $program = $this->program('Only Loyalty Programme');

        $response = $this->createCustomer('Single Loyalty Trading', 'single.loyalty@example.test')
            ->assertCreated();

        $customerId = $response->json('id');

        $this->assertSame(1, CustomerLoyaltyBalance::where('customer_id', $customerId)->count());
        $this->assertDatabaseHas('customer_loyalty_balances', [
            'customer_id' => $customerId,
            'program_id' => $program->id,
            'points_balance' => 0,
        ]);
    }

    public function test_loyalty_balance_failure_rolls_back_the_customer_creation(): void
    {
        $this->program('Rollback Loyalty Programme');

        // Force the balance INSERT to fail after the customer row would have
        // been written. On the fixed path the whole canonical creation
        // transaction must roll back, leaving no customer behind.
        $event = 'eloquent.creating: '.CustomerLoyaltyBalance::class;
        Event::listen($event, function () {
            throw new \RuntimeException('forced loyalty balance failure');
        });

        try {
            $response = $this->createCustomer('Rollback Loyalty Trading', 'rollback.loyalty@example.test');

            $this->assertSame(
                500,
                $response->getStatusCode(),
                'The forced balance failure must surface as a server error, not a silent 201.'
            );
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseMissing('customers', ['name' => 'Rollback Loyalty Trading']);
        $this->assertSame(0, CustomerLoyaltyBalance::count(), 'No orphaned balance rows may survive the rollback.');
    }
}
