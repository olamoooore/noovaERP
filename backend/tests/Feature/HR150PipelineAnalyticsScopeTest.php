<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #150 (hostile re-review) — sales-pipeline analytics:
 *   B1 entity scoping (an entity-scoped user must not see every entity);
 *   B2 mixed currencies were summed unconverted.
 */
class HR150PipelineAnalyticsScopeTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private Customer $customerA;

    private Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['sales_orders', 'quotations', 'company_settings']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR150 Group', 'code' => 'HR150-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'HR150 A', 'code' => 'HR150A-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'HR150 B', 'code' => 'HR150B-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->customerA = Customer::factory()->create(['legal_entity_id' => $this->entityA, 'is_active' => true]);
        $this->customerB = Customer::factory()->create(['legal_entity_id' => $this->entityB, 'is_active' => true]);
    }

    private function order(Customer $customer, float $total, string $currency = 'USD', string $status = 'confirmed'): SalesOrder
    {
        return SalesOrder::create([
            'sales_order_number' => 'SO-150-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'legal_entity_id' => $customer->legal_entity_id,
            'status' => $status,
            'order_date' => now()->toDateString(),
            'currency_code' => $currency,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'created_by' => $this->user->id,
        ]);
    }

    private function grantEntityA(): void
    {
        DB::table('user_entity_access')->insert([
            'user_id' => $this->user->id,
            'legal_entity_id' => $this->entityA,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_entity_scoped_user_only_sees_their_entities_pipeline(): void
    {
        $this->order($this->customerA, 1000);
        $this->order($this->customerB, 5000);

        $this->grantEntityA();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales/analytics/pipeline?from_date='.now()->startOfMonth()->toDateString().'&to_date='.now()->endOfMonth()->toDateString());

        $response->assertOk();
        $this->assertSame(1, $response->json('data.pipeline.open_orders'));
        $this->assertSame(1000.0, (float) $response->json('data.pipeline.open_value'),
            'Only entity A pipeline value may be visible to an entity-A-scoped user.');
    }

    public function test_mixed_currencies_are_converted_to_base_currency(): void
    {
        // Base currency is USD; a 1000 EUR order at 2.0 must count as 2000.
        DB::table('company_settings')->insert([
            'base_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exchange_rates')->insert([
            'from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 2.0,
            'effective_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->order($this->customerA, 1000, 'USD');
        $this->order($this->customerA, 1000, 'EUR');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales/analytics/pipeline?from_date='.now()->startOfMonth()->toDateString().'&to_date='.now()->endOfMonth()->toDateString());

        $response->assertOk();
        $this->assertSame('USD', $response->json('data.base_currency'));
        $this->assertSame(3000.0, (float) $response->json('data.pipeline.open_value'),
            'EUR must be converted (1000 EUR x 2.0 = 2000 USD) before summing.');
    }

    public function test_document_without_a_rate_is_surfaced_not_silently_summed(): void
    {
        DB::table('company_settings')->insert([
            'base_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->order($this->customerA, 1000, 'USD');
        $this->order($this->customerA, 700, 'JPY'); // no JPY rate exists

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales/analytics/pipeline?from_date='.now()->startOfMonth()->toDateString().'&to_date='.now()->endOfMonth()->toDateString());

        $response->assertOk();
        $this->assertSame(1000.0, (float) $response->json('data.pipeline.open_value'));
        $this->assertSame(700.0, (float) $response->json('data.unconverted.JPY'),
            'An unconvertible currency must be reported, not silently mixed in.');
    }

    public function test_quotation_entity_filter_uses_the_customer_entity(): void
    {
        Quotation::create([
            'customer_id' => $this->customerA->id,
            'quotation_number' => 'Q-150-'.Str::upper(Str::random(5)),
            'quotation_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
            'created_by' => $this->user->id,
        ]);
        Quotation::create([
            'customer_id' => $this->customerB->id,
            'quotation_number' => 'Q-150-'.Str::upper(Str::random(5)),
            'quotation_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
            'created_by' => $this->user->id,
        ]);

        $this->grantEntityA();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales/analytics/pipeline?from_date='.now()->startOfMonth()->toDateString().'&to_date='.now()->endOfMonth()->toDateString());

        $response->assertOk();
        $this->assertSame(1, $response->json('data.quotations.total'),
            'Only entity A quotations may be visible.');
    }
}
