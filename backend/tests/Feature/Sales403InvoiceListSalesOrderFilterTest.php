<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #403 — the Sales Order page's Invoices smart button opens
 * /invoices?sales_order_id=<id>. The invoice list must honour that filter
 * server-side and return only the invoices belonging to that sales order:
 * those linked through sales_invoice_links (what the order page counts) and
 * those carrying the order on invoices.sales_order_id.
 */
class Sales403InvoiceListSalesOrderFilterTest extends TenantTestCase
{
    protected User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Sales 403 Filter Tenant',
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

    public function test_invoice_list_returns_only_the_given_sales_orders_invoices(): void
    {
        $customer = Customer::factory()->create(['name' => 'SO Filter Customer']);
        $order = $this->makeOrder($customer);
        $otherOrder = $this->makeOrder($customer);

        $linked = $this->makeInvoice($customer);
        $this->link($order, $linked);

        $byColumn = $this->makeInvoice($customer, ['sales_order_id' => $order->id]);

        $otherOrdersInvoice = $this->makeInvoice($customer, ['sales_order_id' => $otherOrder->id]);
        $this->link($otherOrder, $otherOrdersInvoice);

        $standalone = $this->makeInvoice($customer);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?sales_order_id='.$order->id)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(
            collect([$linked->id, $byColumn->id])->sort()->values()->all(),
            $ids,
            'The invoice list filtered by sales_order_id must contain only that order\'s invoices.'
        );
        $this->assertSame(2, (int) $response->json('total'));

        // The unfiltered list still returns every invoice.
        $all = $this->actingAs($this->user)->getJson('/api/v1/invoices')->assertOk();
        $this->assertSame(4, (int) $all->json('total'));
        $this->assertContains($standalone->id, collect($all->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_ids_only_select_all_respects_the_sales_order_filter(): void
    {
        $customer = Customer::factory()->create(['name' => 'SO Filter Customer']);
        $order = $this->makeOrder($customer);

        $linked = $this->makeInvoice($customer);
        $this->link($order, $linked);
        $this->makeInvoice($customer);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?ids_only=1&sales_order_id='.$order->id)
            ->assertOk();

        $this->assertSame([$linked->id], array_map('intval', $response->json('data')));
    }

    private function makeOrder(Customer $customer): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'created_by' => $this->user->id,
        ]);
    }

    private function makeInvoice(Customer $customer, array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ], $attributes));
    }

    private function link(SalesOrder $order, Invoice $invoice): void
    {
        DB::table('sales_invoice_links')->insert([
            'sales_order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'link_type' => 'primary',
            'link_state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
