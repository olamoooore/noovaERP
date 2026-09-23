<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Tests\TenantTestCase;

/**
 * Issue #153 — Signed proof-of-delivery capture on DeliveryOrder,
 * surfaced on the resulting Invoice for client AP/payment processing.
 *
 * Verifies, via real HTTP (per the repo's engineering SOP §3):
 *
 *   1. POST /sales/delivery-orders/{id}/signed-document accepts an upload
 *      + signer metadata and persists signed_by_name, signed_by_email,
 *      signed_at, signed_document_path, signed_document_uploaded_at,
 *      confirmation_method.
 *   2. GET /sales/delivery-orders/{id}/signed-document streams the file.
 *   3. GET /invoices/{id} surfaces proof_of_delivery resolving through
 *      the linked DeliveryOrder (signed_by_name, signed_at, URL).
 *   4. An Invoice with no linked signed DO returns proof_of_delivery=null.
 *   5. Uploading after the DO is confirmed is rejected (matches the
 *      existing #177 constraint — POD must precede confirm).
 */
class DeliveryOrderProofOfDeliveryTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected Product $product;
    protected Warehouse $warehouse;
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'delivery_orders',
            'invoices',
        ]);

        $this->user = User::create([
            'name' => 'POD Driver',
            'email' => 'pod-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'POD Customer',
            'payment_terms' => 30,
            'billing_address' => 'POD Billing Address',
            'shipping_address' => 'POD Shipping Address',
        ]);

        $this->product = Product::factory()->create([
            'name' => 'POD Widget',
            'sku' => 'POD-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'POD Warehouse',
            'code' => 'PODW-'.uniqid(),
            'is_active' => true,
        ]);

        Storage::fake('local');
    }

    private function makeDraftDeliveryOrder(): DeliveryOrder
    {
        $svc = app(\Modules\Sales\Services\SalesOrderService::class);

        $order = $svc->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $svc->confirmOrder($order);

        $do = DeliveryOrder::create([
            'delivery_order_number' => 'DO-'.uniqid(),
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        return $do;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Upload signed POD with metadata
    // ─────────────────────────────────────────────────────────────────────

    public function test_uploading_signed_document_persists_signer_metadata(): void
    {
        $do = $this->makeDraftDeliveryOrder();
        $file = UploadedFile::fake()->create('signed-do.pdf', 50, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$do->id}/signed-document", [
                'file' => $file,
                'signed_by_name' => 'Jane Receiver',
                'signed_by_email' => 'jane@example.com',
                'signed_at' => '2026-09-22T15:30:00Z',
            ]);

        $response->assertOk();
        $response->assertJsonPath('signed_by_name', 'Jane Receiver');
        $response->assertJsonPath('signed_by_email', 'jane@example.com');

        $do->refresh();
        $this->assertSame('Jane Receiver', $do->signed_by_name);
        $this->assertSame('jane@example.com', $do->signed_by_email);
        $this->assertNotNull($do->signed_at);
        $this->assertNotNull($do->signed_document_path);
        $this->assertNotNull($do->signed_document_uploaded_at);
        $this->assertSame('physical', $do->confirmation_method);
    }

    public function test_uploading_signed_document_requires_signer_name(): void
    {
        $do = $this->makeDraftDeliveryOrder();
        $file = UploadedFile::fake()->create('signed-do.pdf', 50, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$do->id}/signed-document", [
                'file' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['signed_by_name']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Download the signed POD
    // ─────────────────────────────────────────────────────────────────────

    public function test_downloading_signed_document_streams_the_file(): void
    {
        $do = $this->makeDraftDeliveryOrder();
        $file = UploadedFile::fake()->createWithContent('signed-do.pdf', '%PDF-1.4 fake content');

        $this->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$do->id}/signed-document", [
                'file' => $file,
                'signed_by_name' => 'Jane Receiver',
                'signed_by_email' => 'jane@example.com',
            ])
            ->assertOk();

        $response = $this->actingAs($this->user)
            ->get("/api/v1/sales/delivery-orders/{$do->id}/signed-document");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Invoice surfaces proof_of_delivery through the linked DO
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_show_surfaces_proof_of_delivery(): void
    {
        $do = $this->makeDraftDeliveryOrder();
        $file = UploadedFile::fake()->create('signed-do.pdf', 50, 'application/pdf');

        $this->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$do->id}/signed-document", [
                'file' => $file,
                'signed_by_name' => 'Jane Receiver',
                'signed_by_email' => 'jane@example.com',
                'signed_at' => '2026-09-22T15:30:00Z',
            ])
            ->assertOk();

        $invoice = \App\Models\Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);
        $do->update(['invoice_id' => $invoice->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertJsonPath('proof_of_delivery.signed_by_name', 'Jane Receiver');
        $response->assertJsonPath('proof_of_delivery.signed_by_email', 'jane@example.com');
        $response->assertJsonPath('proof_of_delivery.delivery_order_id', $do->id);
        $response->assertJsonPath('proof_of_delivery.confirmation_method', 'physical');
        $this->assertNotNull($response->json('proof_of_delivery.signed_document_url'));
        $this->assertNotNull($response->json('proof_of_delivery.signed_at'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Invoice with no linked signed DO returns proof_of_delivery=null
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_without_signed_delivery_returns_null_proof(): void
    {
        $invoice = \App\Models\Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $this->assertNull($response->json('proof_of_delivery'));
    }
}
