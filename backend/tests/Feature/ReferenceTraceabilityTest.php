<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Reference traceability across the sales document chain.
 *
 * Customer Ref. / PO / Internal Ref. must survive every conversion
 * (quotation → sales order → delivery order → invoice), and any reference must
 * resolve to the whole linked family through /api/v1/reference-trace — real
 * router/controller path via getJson.
 */
class ReferenceTraceabilityTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Traceability Tenant',
            'email' => 'trace@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenantId,
            'role' => 'owner',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);

        // Non-stock product: no warehouse/allocation friction in the chain.
        $this->product = Product::factory()->create([
            'name' => 'Trace Widget',
            'sku' => 'TRACE-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);

        Warehouse::factory()->create(['is_default' => true]);
    }

    private function makeQuotation(string $customerRef, string $internalRef): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-TRACE-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'customer_reference' => $customerRef,
            'internal_reference' => $internalRef,
            'created_by' => $this->owner->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 100,
        ]);

        return $quotation;
    }

    /** @test */
    public function references_survive_the_whole_conversion_chain(): void
    {
        $quotation = $this->makeQuotation('CUST-REF-777', 'INT-REF-888');

        // Quote → Sales Order (confirm releases the quotation).
        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $salesOrder = SalesOrder::findOrFail($quotation->sales_order_id);
        $this->assertSame('CUST-REF-777', $salesOrder->customer_reference);
        $this->assertSame('INT-REF-888', $salesOrder->internal_reference);

        // Quote → Invoice.
        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $invoice = Invoice::where('quotation_id', $quotation->id)->latest('id')->firstOrFail();
        $this->assertSame('CUST-REF-777', $invoice->customer_reference);
        $this->assertSame('INT-REF-888', $invoice->internal_reference);

        // Quote → Delivery Order.
        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        $deliveryOrder = DeliveryOrder::where('quotation_id', $quotation->id)->latest('id')->firstOrFail();
        $this->assertSame('CUST-REF-777', $deliveryOrder->customer_reference);
        $this->assertSame('INT-REF-888', $deliveryOrder->internal_reference);
        $this->assertSame('CUST-REF-777', $deliveryOrder->po_number);
    }

    /** @test */
    public function any_reference_traces_the_whole_document_family(): void
    {
        $quotation = $this->makeQuotation('PO-TRACE-1001', 'PROJ-ALPHA');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        // Search by the CUSTOMER reference: the whole family comes back.
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/reference-trace?q=PO-TRACE-1001')
            ->assertOk();

        $keys = collect($response->json('groups'))->pluck('key')->all();
        $this->assertContains('trace_quotations', $keys);
        $this->assertContains('trace_sales_orders', $keys);
        $this->assertContains('trace_delivery_orders', $keys);
        $this->assertContains('trace_invoices', $keys);

        // And by the INTERNAL reference (project) as well.
        $byInternal = $this->actingAs($this->owner)
            ->getJson('/api/v1/reference-trace?q=PROJ-ALPHA')
            ->assertOk();

        $this->assertGreaterThanOrEqual(4, $byInternal->json('total'));
    }

    /** @test */
    public function global_search_matches_every_reference_column(): void
    {
        $quotation = $this->makeQuotation('SEARCH-PO-1', 'SEARCH-INT-1');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        // Internal reference must hit both the quotation and (via propagation)
        // the sales order and invoice documents.
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/search?q=SEARCH-INT-1')
            ->assertOk();

        $keys = collect($response->json('groups'))->pluck('key')->all();
        $this->assertContains('quotations', $keys);
        $this->assertContains('sales_orders', $keys);
        $this->assertContains('invoices', $keys);
    }

    /** @test */
    public function trace_requires_only_what_the_caller_may_see(): void
    {
        $viewer = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $viewer->id,
            'tenant_id' => $this->tenantId,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quotation = $this->makeQuotation('VIEWER-PO-1', 'VIEWER-INT-1');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        // Viewer holds sales.view + invoices.view (seeded matrix), so the trace
        // still resolves — and never leaks anything it cannot open.
        $response = $this->actingAs($viewer)
            ->getJson('/api/v1/reference-trace?q=VIEWER-PO-1')
            ->assertOk();

        $urls = collect($response->json('groups'))->flatMap(fn ($group) => collect($group['items'])->pluck('url'));
        $this->assertTrue($urls->isNotEmpty());
        $this->assertTrue($urls->every(fn ($url) => str_starts_with($url, '/quotations/')
            || str_starts_with($url, '/sales/orders/')
            || str_starts_with($url, '/delivery-orders/')
            || str_starts_with($url, '/invoices/')));
    }
}
