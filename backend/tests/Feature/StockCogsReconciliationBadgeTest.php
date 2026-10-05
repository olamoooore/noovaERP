<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\StockCogsReconciliationReportCommand;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionType;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #696 (SALES-DIFF / INV-D3): the stock & COGS reconciliation badge on
 * every sales document, and the nightly mismatch report.
 *
 *   • A single goods issue must show green: net issued == sold and exactly one
 *     active COGS posting.
 *   • A double issue (#682 legacy data) must show red with the offending
 *     movements listed, so an accountant can open the documents.
 *   • Bundles decompose into component movements but still post one
 *     document-level COGS entry — green, no false positive.
 *   • Returns / reversals net the sale and the COGS out — green, no false
 *     positive.
 *   • The nightly command records ONLY mismatched documents in #902's
 *     exception queue, once each.
 *
 * Seeding drives the real routes (router → middleware → controller); the
 * double issue is seeded as the extra `sale` movement the pre-#682 code left
 * behind (the fixed completion/invoice paths no longer create it).
 */
class StockCogsReconciliationBadgeTest extends TenantTestCase
{
    private const UNIT_COST = 10.00;

    private const OPENING_STOCK = 200;

    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Stock COGS Badge Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);

        $this->warehouse = Warehouse::create([
            'name' => 'Badge Warehouse',
            'code' => 'BW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Badge Widget',
            'sku' => 'BWX-'.uniqid(),
            'track_inventory' => true,
            'cost' => self::UNIT_COST,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => self::OPENING_STOCK,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function apiPost(string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson($uri, $payload);
    }

    private function badge(string $uri): array
    {
        $response = $this->actingAs($this->admin)->getJson($uri);
        $response->assertOk();

        return $response->json('data');
    }

    private function directInvoice(float $quantity): int
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function postInvoice(int $invoiceId): void
    {
        $this->apiPost("/api/v1/invoices/{$invoiceId}/post")->assertOk();
    }

    private function confirmedSalesOrder(float $quantity, ?Product $product = null): int
    {
        $product ??= $this->product;

        $response = $this->apiPost('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $orderId = (int) $response->json('id');

        SalesOrder::whereKey($orderId)->update(['warehouse_id' => $this->warehouse->id]);
        $this->apiPost("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        return $orderId;
    }

    private function deliveryOrderFor(int $orderId, float $quantity): int
    {
        $order = SalesOrder::with('lines')->findOrFail($orderId);

        $response = $this->apiPost('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $orderId,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => $quantity,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function invoiceFromSalesOrder(int $orderId): int
    {
        $response = $this->apiPost("/api/v1/sales/orders/{$orderId}/invoice");
        $response->assertCreated();

        return (int) $response->json('invoice.id');
    }

    /**
     * Seed the pre-#682 double issue: the invoice reference carries a `sale`
     * movement the fixed posting path would never write (it had already been
     * issued by the delivery). The unique constraint allows it because the
     * reference (Invoice) differs from the delivery's.
     */
    private function seedLegacyDuplicateIssue(int $invoiceId, float $quantity): void
    {
        StockMovement::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'sale',
            'quantity' => $quantity,
            'reference' => 'LEGACY-'.$invoiceId,
            'reference_type' => 'Invoice',
            'reference_id' => $invoiceId,
            'created_by' => $this->admin->id,
            'balance_after' => self::OPENING_STOCK - $quantity,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A single, honest issue is green
    // ─────────────────────────────────────────────────────────────────────

    public function test_single_issue_invoice_badge_is_green_with_one_cogs_posting(): void
    {
        $invoiceId = $this->directInvoice(10);
        $this->postInvoice($invoiceId);

        $badge = $this->badge("/api/v1/invoices/{$invoiceId}/stock-cogs-badge");

        $this->assertFalse((bool) $badge['has_mismatch']);
        $this->assertTrue((bool) $badge['stock']['ok']);
        $this->assertSame(10.0, (float) $badge['stock']['issued']);
        $this->assertSame(10.0, (float) $badge['stock']['sold']);
        $this->assertSame([], $badge['stock']['offenders']);
        $this->assertTrue((bool) $badge['cogs']['ok']);
        $this->assertSame(1, (int) $badge['cogs']['posted_count']);
        $this->assertSame([], $badge['cogs']['offenders']);
    }

    public function test_badge_is_green_on_a_fully_delivered_order_too(): void
    {
        $orderId = $this->confirmedSalesOrder(10);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 10);
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();

        $badge = $this->badge("/api/v1/sales/delivery-orders/{$deliveryOrderId}/stock-cogs-badge");

        $this->assertFalse((bool) $badge['has_mismatch']);
        $this->assertSame(10.0, (float) $badge['stock']['issued']);
        $this->assertSame(10.0, (float) $badge['stock']['sold']);
        $this->assertSame(1, (int) $badge['cogs']['posted_count']);

        $orderBadge = $this->badge("/api/v1/sales/orders/{$orderId}/stock-cogs-badge");
        $this->assertFalse((bool) $orderBadge['has_mismatch']);
        $this->assertSame(10.0, (float) $orderBadge['stock']['issued']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A double issue is red with the offending movements listed
    // ─────────────────────────────────────────────────────────────────────

    public function test_double_issue_badge_is_red_and_lists_the_offending_movements(): void
    {
        $orderId = $this->confirmedSalesOrder(10);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 10);
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();

        $invoiceId = $this->invoiceFromSalesOrder($orderId);
        $this->postInvoice($invoiceId);

        // The pre-#682 legacy data: the same 10 units were issued by the
        // invoice as well.
        $this->seedLegacyDuplicateIssue($invoiceId, 10);

        $badge = $this->badge("/api/v1/sales/orders/{$orderId}/stock-cogs-badge");

        $this->assertTrue((bool) $badge['has_mismatch']);
        $this->assertFalse((bool) $badge['stock']['ok']);
        $this->assertSame(20.0, (float) $badge['stock']['issued'], '20 units left the warehouse but only 10 were sold');
        $this->assertSame(10.0, (float) $badge['stock']['sold']);

        $this->assertCount(1, $badge['stock']['offenders']);
        $offender = $badge['stock']['offenders'][0];
        $this->assertSame('over_issued', $offender['kind']);
        $this->assertSame($this->product->id, (int) $offender['product_id']);
        $this->assertSame(10.0, (float) $offender['excess']);
        $this->assertSame(10.0, (float) $offender['expected']);
        $this->assertSame(20.0, (float) $offender['issued']);

        // The offender names BOTH movements an accountant must open.
        $sourceTypes = collect($offender['movements'])->pluck('source_document.type')->unique()->sort()->values()->all();
        $this->assertSame(['DeliveryOrder', 'Invoice'], $sourceTypes);
        $this->assertSame(
            [$deliveryOrderId, $invoiceId],
            collect($offender['movements'])->pluck('source_document.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        );

        // The seeded invoice issue has no COGS entry at all — the COGS half
        // says so on the same document.
        $this->assertFalse((bool) $badge['cogs']['ok']);
        $this->assertContains(
            'missing_cogs',
            collect($badge['cogs']['offenders'])->pluck('kind')->all(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bundles decompose — green, no false positive
    // ─────────────────────────────────────────────────────────────────────

    public function test_bundle_sale_badge_is_green_with_decomposed_component_demand(): void
    {
        $bundle = Product::factory()->create([
            'name' => 'Badge Gift Basket',
            'sku' => 'BGB-'.uniqid(),
            'is_bundle' => true,
            'track_inventory' => false,
            'cost' => 0,
            'price' => 50,
        ]);

        $componentA = Product::factory()->create([
            'name' => 'Badge Red Wine',
            'sku' => 'BRW-'.uniqid(),
            'track_inventory' => true,
            'cost' => 5.00,
            'price' => 20,
        ]);

        $componentB = Product::factory()->create([
            'name' => 'Badge Chocolate',
            'sku' => 'BCH-'.uniqid(),
            'track_inventory' => true,
            'cost' => 3.00,
            'price' => 10,
        ]);

        ProductBundle::create([
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $componentA->id,
            'quantity' => 2,
        ]);
        ProductBundle::create([
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $componentB->id,
            'quantity' => 1,
        ]);

        foreach ([$componentA, $componentB] as $component) {
            StockLevel::create([
                'product_id' => $component->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 50,
                'reserved_quantity' => 0,
                'min_quantity' => 0,
            ]);
        }

        // The bundle must ship off an order (or the invoice resolves no
        // warehouse for the decomposed components).
        $orderId = $this->confirmedSalesOrder(2, $bundle);
        $invoiceId = $this->invoiceFromSalesOrder($orderId);

        $this->postInvoice($invoiceId);

        $badge = $this->badge("/api/v1/invoices/{$invoiceId}/stock-cogs-badge");

        $this->assertFalse((bool) $badge['has_mismatch'], 'A decomposed bundle must not be a mismatch');
        $this->assertTrue((bool) $badge['stock']['ok']);
        // 2 baskets = 2 x Red Wine + 1 x Chocolate each = 6 component units.
        $this->assertSame(6.0, (float) $badge['stock']['issued']);
        $this->assertSame(6.0, (float) $badge['stock']['sold']);
        $this->assertTrue((bool) $badge['cogs']['ok']);
        $this->assertSame(1, (int) $badge['cogs']['posted_count'], 'The bundle posts ONE document-level COGS entry');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Returns / reversals net out — green, no false positive
    // ─────────────────────────────────────────────────────────────────────

    public function test_reversed_delivery_badge_is_green_because_returns_net_out(): void
    {
        $orderId = $this->confirmedSalesOrder(10);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 10);
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();

        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/reverse", [
            'reason' => 'Badge test reversal',
        ])->assertOk();

        foreach ([
            "/api/v1/sales/delivery-orders/{$deliveryOrderId}/stock-cogs-badge",
            "/api/v1/sales/orders/{$orderId}/stock-cogs-badge",
        ] as $uri) {
            $badge = $this->badge($uri);

            $this->assertFalse((bool) $badge['has_mismatch'], "Reversal must not be a mismatch: {$uri}");
            $this->assertTrue((bool) $badge['stock']['ok']);
            $this->assertSame([], $badge['stock']['offenders']);
            $this->assertTrue((bool) $badge['cogs']['ok']);
            $this->assertSame(0, (int) $badge['cogs']['posted_count'], 'The reversal retired the COGS posting');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The nightly report records exactly the mismatched documents
    // ─────────────────────────────────────────────────────────────────────

    public function test_nightly_report_records_only_mismatched_documents(): void
    {
        // A clean posted invoice must never be queued.
        $cleanInvoiceId = $this->directInvoice(4);
        $this->postInvoice($cleanInvoiceId);

        // A legacy double issue on a delivery + invoice pair must be queued.
        $orderId = $this->confirmedSalesOrder(10);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 10);
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();
        $dirtyInvoiceId = $this->invoiceFromSalesOrder($orderId);
        $this->postInvoice($dirtyInvoiceId);
        $this->seedLegacyDuplicateIssue($dirtyInvoiceId, 10);

        $command = app(StockCogsReconciliationReportCommand::class);

        $firstRun = $command->reconcileTenant($this->boundTestTenant);

        $this->assertNotEmpty($firstRun['mismatches'], 'The nightly report must find the seeded mismatch');
        $this->assertGreaterThanOrEqual(1, $firstRun['recorded'], 'The mismatch must be queued for accountants');

        $mismatchRows = ExceptionQueue::where('tenant_id', $this->tenantId)
            ->where('exception_type', ExceptionType::INVENTORY_STOCK_COGS_MISMATCH->value)
            ->get();

        $this->assertGreaterThanOrEqual(1, $mismatchRows->count(), 'The nightly report must record the mismatch');
        $this->assertTrue(
            $mismatchRows->contains(fn (ExceptionQueue $row) => $row->document_type === 'sales_order'
                && (int) $row->document_id === $orderId),
            'The mismatched sales order must be queued',
        );
        $this->assertFalse(
            $mismatchRows->contains(fn (ExceptionQueue $row) => $row->document_type === 'invoice'
                && (int) $row->document_id === $cleanInvoiceId),
            'A clean, single-issue invoice must never be queued',
        );

        $row = $mismatchRows->firstWhere('document_type', 'sales_order');
        $this->assertSame('accountant', $row->owner_role, 'The queue routes the mismatch to accountants');
        $this->assertNull($row->retry_handler, 'No auto-retry exists — the fix is the document correction path');
        $this->assertNotEmpty($row->context['stock']['offenders'] ?? []);
        $this->assertArrayHasKey('fix', $row->context, 'The payload points at the existing correction path');

        // Running again does not duplicate the queue.
        $command->reconcileTenant($this->boundTestTenant);
        $this->assertSame(
            $mismatchRows->count(),
            ExceptionQueue::where('tenant_id', $this->tenantId)
                ->where('exception_type', ExceptionType::INVENTORY_STOCK_COGS_MISMATCH->value)
                ->count(),
            'A second nightly run must not raise the same open mismatch again',
        );
    }

    public function test_nightly_report_command_iterates_tenants(): void
    {
        $this->assertContains(
            \App\Console\Concerns\IteratesTenants::class,
            class_uses_recursive(StockCogsReconciliationReportCommand::class),
        );
    }
}
