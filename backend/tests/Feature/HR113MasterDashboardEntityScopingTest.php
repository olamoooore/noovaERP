<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #113 (HIGH) — the Master Dashboard aggregated every legal entity in the
 * tenant. Two entities with distinct data + a user scoped to one entity must
 * return only that entity's figures.
 *
 * Real HTTP through the router.
 */
class HR113MasterDashboardEntityScopingTest extends TenantTestCase
{
    private User $scopedToA;

    private User $scopedToBoth;

    private User $unscoped;

    private Customer $customerA;

    private Customer $customerB;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Dashboard Entity Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Dashboard Group',
            'code' => 'DG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'EA-'.Str::upper(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'EB-'.Str::upper(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customerA = Customer::factory()->create(['legal_entity_id' => $entityA, 'is_active' => true]);
        $this->customerB = Customer::factory()->create(['legal_entity_id' => $entityB, 'is_active' => true]);

        // Entity A: 100 outstanding; Entity B: 500 outstanding.
        Invoice::factory()->create([
            'customer_id' => $this->customerA->id,
            'status' => 'sent',
            'invoice_date' => now()->toDateString(),
            'total' => 100.00,
            'balance' => 100.00,
        ]);
        Invoice::factory()->create([
            'customer_id' => $this->customerB->id,
            'status' => 'sent',
            'invoice_date' => now()->toDateString(),
            'total' => 500.00,
            'balance' => 500.00,
        ]);

        Product::factory()->create(['is_active' => true]);
        Product::factory()->create(['is_active' => true]);

        $this->scopedToA = $this->userWithEntityAccess([$entityA]);
        $this->scopedToBoth = $this->userWithEntityAccess([$entityA, $entityB]);
        $this->unscoped = User::factory()->create();
    }

    private function userWithEntityAccess(array $entityIds): User
    {
        $user = User::factory()->create();

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'viewer',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function dashboardFor(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/dashboard?range=30d');
        $response->assertStatus(200, $response->getContent());

        return $response->json();
    }

    public function test_single_entity_user_sees_only_their_entity_figures(): void
    {
        $stats = $this->dashboardFor($this->scopedToA);

        $this->assertTrue($stats['entity_scoped']);
        $this->assertSame(100.0, (float) $stats['total_revenue']);
        $this->assertSame(1, $stats['outstanding_invoices']['count']);
        $this->assertSame(100.0, (float) $stats['outstanding_invoices']['amount']);
        $this->assertSame(1, $stats['total_customers']);
        $this->assertCount(1, $stats['recent_invoices']);
        $this->assertSame($this->customerA->id, $stats['recent_invoices'][0]['customer_id']);

        $trendTotal = array_sum(array_column($stats['revenue_trend'], 'v'));
        $this->assertSame(100.0, (float) $trendTotal);
    }

    public function test_multi_entity_user_sees_the_sum_of_their_entities_only(): void
    {
        $stats = $this->dashboardFor($this->scopedToBoth);

        $this->assertTrue($stats['entity_scoped']);
        $this->assertSame(600.0, (float) $stats['total_revenue']);
        $this->assertSame(2, $stats['outstanding_invoices']['count']);
        $this->assertSame(600.0, (float) $stats['outstanding_invoices']['amount']);
        $this->assertSame(2, $stats['total_customers']);
        $this->assertCount(2, $stats['recent_invoices']);
    }

    public function test_user_without_entity_grants_keeps_the_fail_open_default(): void
    {
        // Mirrors EnsureEntityAccess: no entity grants means single-entity
        // tenants / entity model unused, so the historical dashboard is kept.
        $stats = $this->dashboardFor($this->unscoped);

        $this->assertFalse($stats['entity_scoped']);
        $this->assertSame(600.0, (float) $stats['total_revenue']);
        $this->assertSame(2, $stats['total_customers']);
    }

    public function test_product_counts_remain_tenant_global(): void
    {
        // Products carry no legal_entity_id — deliberately unscoped (catalogue
        // is tenant-global, not a financial figure).
        $stats = $this->dashboardFor($this->scopedToA);

        $this->assertSame(2, $stats['total_products']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }
}
