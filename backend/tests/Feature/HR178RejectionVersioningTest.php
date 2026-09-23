<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #178/#179 — quotation and delivery-order rejection must auto-version:
 * the original is voided (preserved) and a linked replacement draft is created
 * with the same correction group and version+1.
 */
class HR178RejectionVersioningTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '17817817-8192-9304-0516-273849506172',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'quotations', 'quotation_lines', 'delivery_orders', 'delivery_order_lines',
            'customers', 'products', 'document_correction_links',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function quotation(): Quotation
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-HR178-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'status' => 'sent',
            'subtotal' => 200,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 200,
            'created_by' => $this->user->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'description' => 'HR178 line',
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => 200,
        ]);

        return $quotation;
    }

    private function deliveryOrder(): DeliveryOrder
    {
        $order = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'status' => 'confirmed',
            'source' => 'standalone',
            'delivery_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'description' => 'HR178 DO line',
            'product_id' => $this->product->id,
            'quantity_ordered' => 3,
            'quantity_delivered' => 0,
            'unit_price' => 50,
            'line_total' => 150,
        ]);

        return $order;
    }

    public function test_quotation_rejection_creates_a_linked_replacement_version(): void
    {
        $original = $this->quotation();

        $response = $this->actingAs($this->user)->postJson('/api/v1/quotations/'.$original->id.'/reject', [
            'reason_code' => 'price_error',
            'reason_text' => 'Customer disputed pricing',
        ]);

        $response->assertStatus(201);

        $original->refresh();
        $this->assertSame('cancelled', $original->status, 'The rejected original must be voided.');
        $this->assertFalse((bool) $original->is_active_version);
        $this->assertNotNull($original->superseded_at);
        $this->assertNotNull($original->correction_group_id);

        $replacement = Quotation::findOrFail($response->json('quotation.id'));
        $this->assertSame(2, (int) $replacement->version_number);
        $this->assertSame($original->correction_group_id, $replacement->correction_group_id);
        $this->assertTrue((bool) $replacement->is_active_version);
        $this->assertSame('draft', $replacement->status);
        $this->assertSame($original->id, (int) $replacement->original_document_id);
        $this->assertSame($replacement->id, (int) $original->replacement_document_id);
        $this->assertSame(1, $replacement->lines()->count(), 'Lines must be cloned into the replacement.');
        $this->assertStringContainsString('-R02', $replacement->quotation_number);

        $this->assertDatabaseHas('document_correction_links', [
            'document_type' => 'quotation',
            'document_id' => $original->id,
            'related_document_id' => $replacement->id,
        ]);
    }

    public function test_delivery_order_rejection_creates_a_linked_replacement_version(): void
    {
        $original = $this->deliveryOrder();

        $response = $this->actingAs($this->user)->postJson('/api/v1/sales/delivery-orders/'.$original->id.'/reject', [
            'reason_code' => 'address_error',
            'reason_text' => 'Wrong delivery address',
        ]);

        $response->assertStatus(201);

        $original->refresh();
        $this->assertSame('cancelled', $original->status);
        $this->assertFalse((bool) $original->is_active_version);
        $this->assertNotNull($original->correction_group_id);

        $replacement = DeliveryOrder::findOrFail($response->json('data.id'));
        $this->assertSame(2, (int) $replacement->version_number);
        $this->assertSame($original->correction_group_id, $replacement->correction_group_id);
        $this->assertTrue((bool) $replacement->is_active_version);
        $this->assertSame('draft', $replacement->status);
        $this->assertSame(0.0, (float) $replacement->lines()->first()->quantity_delivered, 'The replacement restarts undelivered.');
        $this->assertSame(1, $replacement->lines()->count());

        $this->assertDatabaseHas('document_correction_links', [
            'document_type' => 'delivery_order',
            'document_id' => $original->id,
            'related_document_id' => $replacement->id,
        ]);
    }

    public function test_an_already_rejected_quotation_cannot_be_rejected_again(): void
    {
        $original = $this->quotation();

        $this->actingAs($this->user)->postJson('/api/v1/quotations/'.$original->id.'/reject')->assertStatus(201);

        $this->actingAs($this->user)
            ->postJson('/api/v1/quotations/'.$original->id.'/reject')
            ->assertStatus(422);
    }
}
