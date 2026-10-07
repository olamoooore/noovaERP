<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FeatureGateService;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #336 — Decision (7 Oct 2026, product owner):
 *  - Rejecting a quotation that is already `confirmed` (has a live sales
 *    order) is refused with 422 and a clear message: cancel the sales order
 *    first.
 *  - The replacement draft never copies `sales_order_id` from the original.
 *
 * Exercised through the real router → middleware → controller chain.
 */
class Issue336RejectConfirmedQuotationTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->product = Product::factory()->create([
            'name' => 'Reject Widget',
            'sku' => 'RW-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);
        $this->entitleSales();
    }

    private function quotation(string $status): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q336-'.Str::upper(Str::random(6)),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => $status,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'created_by' => $this->admin->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 0,
            'tax_amount' => 0,
        ]);

        $quotation->load('lines');
        $quotation->calculateTotals();

        return $quotation->fresh();
    }

    private function confirm(Quotation $quotation): int
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $salesOrderId = $quotation->fresh()->sales_order_id;
        $this->assertNotNull($salesOrderId, 'Confirming must create the sales order.');

        return (int) $salesOrderId;
    }

    private function liveSalesOrderCount(): int
    {
        return DB::table('sales_orders')
            ->where('customer_id', $this->customer->id)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    public function test_rejecting_a_confirmed_quotation_is_refused_and_leaves_exactly_one_live_sales_order(): void
    {
        $quotation = $this->quotation('draft');
        $salesOrderId = $this->confirm($quotation);
        $quotationCount = Quotation::count();

        $response = $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotation->id}/reject", [
            'reason_code' => 'price_error',
            'reason_text' => 'Customer changed mind',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase(
            'cancel the sales order',
            (string) $response->json('message'),
            'The refusal must tell the user to cancel the sales order first.',
        );

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status, 'The confirmed quotation must not be cancelled.');
        $this->assertTrue((bool) $quotation->is_active_version);
        $this->assertSame($salesOrderId, (int) $quotation->sales_order_id);
        $this->assertSame($quotationCount, Quotation::count(), 'No replacement draft may be created.');

        $this->assertSame('confirmed', DB::table('sales_orders')->where('id', $salesOrderId)->value('status'));
        $this->assertSame(1, $this->liveSalesOrderCount(), 'Exactly one live sales order must exist.');
    }

    public function test_rejecting_a_sent_quotation_creates_a_draft_without_a_sales_order(): void
    {
        $quotation = $this->quotation('sent');

        $response = $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotation->id}/reject", [
            'reason_code' => 'price_error',
        ]);

        $response->assertStatus(201);
        $replacement = Quotation::findOrFail($response->json('quotation.id'));
        $this->assertSame('draft', $replacement->status);
        $this->assertNull($replacement->sales_order_id);
    }

    public function test_after_the_sales_order_is_cancelled_the_replacement_draft_does_not_inherit_it(): void
    {
        $quotation = $this->quotation('draft');
        $salesOrderId = $this->confirm($quotation);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$salesOrderId}/cancel", ['reason' => 'Customer withdrew'])
            ->assertOk();

        $response = $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotation->id}/reject", [
            'reason_code' => 'customer_change',
        ]);

        $response->assertStatus(201);
        $replacement = Quotation::findOrFail($response->json('quotation.id'));
        $this->assertSame('draft', $replacement->status);
        $this->assertNull(
            $replacement->sales_order_id,
            'The replacement draft must never copy sales_order_id from the original.',
        );

        // Confirming v2 then yields its own (single) live sales order.
        $newSalesOrderId = $this->confirm($replacement);
        $this->assertNotSame($salesOrderId, $newSalesOrderId);
        $this->assertSame(1, $this->liveSalesOrderCount(), 'Exactly one live sales order must exist.');
    }

    private function entitleSales(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')->andReturn([['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']]);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }
}
