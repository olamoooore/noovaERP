<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #295 (Q7): quotations validated lines.*.quantity as integer|min:0, so
 * a quote priced by kg/hour/metre (2.5) was rejected with a generic error and
 * an empty quantity was only caught by the API. The same domain rule as
 * invoices (positive, at most 4 decimals) now applies, and the frontend
 * validates before submitting.
 */
class QuotationDecimalQuantityTest extends TenantTestCase
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
            'name' => 'Yarn by the metre',
            'sku' => 'YRN-'.Str::upper(Str::random(6)),
            'price' => 4,
            'cost' => 1,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);
    }

    private function storeQuotation(float|string $quantity)
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 4,
                'tax_rate' => 0,
            ]],
        ]);
    }

    public function test_a_decimal_quantity_is_accepted_and_carried_to_order_and_invoice(): void
    {
        $response = $this->storeQuotation(2.5);
        $response->assertCreated();

        $quotationId = $response->json('id') ?? $response->json('data.id');
        $this->assertEqualsWithDelta(2.5, (float) DB::table('quotation_lines')->where('quotation_id', $quotationId)->value('quantity'), 0.0001);

        // Quote → Sales Order.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotationId}/confirm")
            ->assertOk();

        $soId = DB::table('quotations')->where('id', $quotationId)->value('sales_order_id');
        $this->assertNotNull($soId, 'confirming the quotation creates the sales order');
        $this->assertEqualsWithDelta(
            2.5,
            (float) DB::table('sales_order_lines')->where('sales_order_id', $soId)->value('quantity'),
            0.0001,
            'the decimal quantity must carry to the sales order line',
        );

        // Sales Order → Invoice.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotationId}/create-invoice")
            ->assertCreated();

        $invoiceId = DB::table('invoices')->where('quotation_id', $quotationId)->latest('id')->value('id');
        $this->assertEqualsWithDelta(
            2.5,
            (float) DB::table('invoice_lines')->where('invoice_id', $invoiceId)->value('quantity'),
            0.0001,
            'the decimal quantity must carry to the invoice line',
        );
    }

    public function test_zero_and_empty_quantities_are_rejected(): void
    {
        $this->storeQuotation(0)->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
        $this->storeQuotation('')->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
    }

    public function test_more_than_four_decimal_places_are_rejected(): void
    {
        $this->storeQuotation(2.55555)->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
    }
}
