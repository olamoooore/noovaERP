<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #382 (O13): the Sales Orders list must sort server-side across ALL
 * pages via allow-listed `sort` / `direction` parameters, not reorder only
 * the rows of the current page.
 */
class Issue382SalesOrderListServerSortTest extends TenantTestCase
{
    protected User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\EnsureInventoryAccess::class);

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'SO Sort Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::MANAGER->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'manager',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(Customer $customer, float $total, string $status = 'draft', ?string $createdAt = null): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
        // Totals are derived from lines by the service; set directly for the list fixture.
        DB::table('sales_orders')->where('id', $order->id)->update([
            'total' => $total,
            'created_at' => $createdAt ?? now(),
        ]);

        return $order->fresh();
    }

    /** @return int[] */
    private function pageIds(string $query): array
    {
        return collect(
            $this->actingAs($this->user)->getJson('/api/v1/sales/orders?'.$query)->assertOk()->json('data')
        )->pluck('id')->map('intval')->all();
    }

    public function test_sort_by_total_orders_across_all_pages_not_just_the_current_page(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme']);
        // Newest-created has the smallest total, so created_at-desc pagination
        // would put the largest total on the LAST page.
        $big = $this->order($customer, 9000, 'draft', now()->subDays(3)->toDateTimeString());
        $mid = $this->order($customer, 500, 'draft', now()->subDays(2)->toDateTimeString());
        $small = $this->order($customer, 10, 'draft', now()->subDay()->toDateTimeString());

        $this->assertSame([$big->id], $this->pageIds('sort=total&direction=desc&per_page=1&page=1'));
        $this->assertSame([$small->id], $this->pageIds('sort=total&direction=asc&per_page=1&page=1'));
        $this->assertSame([$small->id, $mid->id, $big->id], $this->pageIds('sort=total&direction=asc&per_page=50'));
    }

    public function test_sort_by_customer_name_and_status(): void
    {
        $zed = Customer::factory()->create(['name' => 'Zed Corp']);
        $alpha = Customer::factory()->create(['name' => 'Alpha Ltd']);
        $zedOrder = $this->order($zed, 1, 'draft', now()->subDay()->toDateTimeString());
        $alphaOrder = $this->order($alpha, 1, 'confirmed', now()->subDays(2)->toDateTimeString());

        $this->assertSame([$alphaOrder->id, $zedOrder->id], $this->pageIds('sort=customer.name&direction=asc&per_page=50'));
        $this->assertSame([$zedOrder->id, $alphaOrder->id], $this->pageIds('sort=customer.name&direction=desc&per_page=50'));
        $this->assertSame([$alphaOrder->id, $zedOrder->id], $this->pageIds('sort=status&direction=asc&per_page=50'));
    }

    public function test_unknown_sort_column_falls_back_to_newest_first(): void
    {
        $customer = Customer::factory()->create();
        $older = $this->order($customer, 1, 'draft', now()->subDays(2)->toDateTimeString());
        $newer = $this->order($customer, 2, 'draft', now()->subDay()->toDateTimeString());

        $this->assertSame([$newer->id, $older->id], $this->pageIds('sort=password;drop&direction=sideways&per_page=50'));
    }
}
