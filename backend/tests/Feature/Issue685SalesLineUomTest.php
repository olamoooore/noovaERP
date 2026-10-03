<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #685 [SALES-INV] P1 (IN5) — sales lines had no unit of measure. Every
 * quantity was implicitly the product's stock unit, so a product stocked in
 * pieces but sold by the box could not be modelled: "2 Box" moved 2 pieces of
 * stock, COGS was costed on 2 units, and the invoice (and its e-invoice unit
 * code) had no way to say "BX".
 *
 * The tests drive the REAL routes (router → middleware → controller) and
 * assert the issue's business rule: a line entered in a sales UoM keeps its
 * quantity and per-sales-UoM price on the document, while every stock/COGS
 * path converts quantity × factor into the STOCK UoM. The UN/ECE code is a
 * user-entered field on the UoM record, not a hard-coded table.
 */
class Issue685SalesLineUomTest extends TenantTestCase
{
    private const OPENING_STOCK = 100;

    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private ProductUom $box;

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
            'name' => 'Sales Line UoM Tenant',
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

        // Posting needs an open accounting period.
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
            'name' => 'UoM Sales Warehouse',
            'code' => 'UOM-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        // Stock unit is the piece (cost 10, catalogue price 25 per piece).
        $this->product = Product::factory()->create([
            'name' => 'UoM Sales Widget',
            'sku' => 'UOM-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10.00,
            'price' => 25.00,
            'stock_quantity' => 0,
        ]);

        // Sales UoM: 1 Box = 12 pieces. The UN/ECE code is an admin-entered
        // field on the record (nullable), never a hard-coded list.
        $this->box = ProductUom::create([
            'product_id' => $this->product->id,
            'name' => 'Box',
            'abbreviation' => 'BX',
            'category' => 'count',
            'conversion_factor' => 12,
            'is_base_unit' => false,
            'is_purchase_unit' => false,
            'is_sales_unit' => true,
            'price_multiplier' => 1,
            'is_active' => true,
            'unece_code' => 'BX',
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => self::OPENING_STOCK,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        \Modules\Inventory\Models\InventorySettings::current()->update(['allow_negative_stock' => false]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures / helpers
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

    /** Direct invoice-form invoice (creates its own backing sales order). */
    private function directInvoice(float $quantity, ?ProductUom $uom = null, ?float $unitPrice = null): int
    {
        $line = [
            'product_id' => $this->product->id,
            'description' => '2 boxes of widgets',
            'quantity' => $quantity,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ];

        if ($uom) {
            $line['uom_id'] = $uom->id;
            $line['unit_price'] = $unitPrice ?? 300;
        } else {
            $line['unit_price'] = $unitPrice ?? 25;
        }

        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [$line],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function postInvoice(int $invoiceId): void
    {
        $this->apiPost("/api/v1/invoices/{$invoiceId}/post")->assertOk();
    }

    private function stockOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function stockOut(): float
    {
        return (float) StockMovement::where('type', 'sale')
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    /** COGS recognised on the entry posted by one specific source document. */
    private function cogsForDocument(string $sourceDocumentType, int $sourceDocumentId): float
    {
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');

        $entryIds = JournalEntryLink::where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->pluck('journal_entry_id');

        return (float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $cogsAccountId)
            ->sum('debit');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The acceptance test: sell 2 Box (12 each) → stock −24, invoice shows 2 BX
    // ═══════════════════════════════════════════════════════════════════════

    public function test_selling_two_boxes_moves_stock_in_stock_units_and_invoice_shows_the_sales_uom(): void
    {
        $invoiceId = $this->directInvoice(2, $this->box, 300);

        $this->postInvoice($invoiceId);

        // Stock and COGS live in the STOCK UoM: 2 Box × 12 = 24 pieces.
        $this->assertSame(76.0, $this->stockOnHand(), '2 Boxes must deduct 24 pieces of stock');
        $this->assertSame(24.0, $this->stockOut(), 'The stock movement must be 24 stock units');
        $this->assertSame(
            240.0,
            $this->cogsForDocument('invoice', $invoiceId),
            'COGS must be 24 stock units × 10.00 cost = 240.00'
        );

        // The invoice line stays in the SALES UoM and is priced per sales UoM.
        $line = Invoice::query()->findOrFail($invoiceId)->lines()->first();
        $this->assertSame(2.0, (float) $line->quantity, 'The invoice line must show 2 Box, not 24');
        $this->assertSame((int) $this->box->id, (int) $line->uom_id);
        $this->assertSame('BX', $line->unit);
        $this->assertSame(300.0, (float) $line->unit_price, 'Prices are per sales UoM (per Box)');

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame(600.0, (float) $invoice->subtotal, '2 Box × 300.00 per Box = 600.00');
        $this->assertSame(600.0, (float) $invoice->total);

        // The API representation carries "2 BX" and the UoM's UN/ECE code.
        $response = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoiceId}");
        $response->assertOk();
        $this->assertSame(2.0, (float) $response->json('lines.0.quantity'));
        $this->assertSame('BX', $response->json('lines.0.unit'));
        $this->assertSame('BX', $response->json('lines.0.uom.abbreviation'));
        $this->assertSame('BX', $response->json('lines.0.uom.unece_code'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The goods-issue path also converts to the stock UoM
    // ═══════════════════════════════════════════════════════════════════════

    public function test_delivery_order_selling_two_boxes_issues_24_stock_units(): void
    {
        $response = $this->apiPost('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => '2 boxes of widgets',
                'quantity_delivered' => 2,
                'uom_id' => $this->box->id,
                'unit_price' => 300,
            ]],
        ]);
        $response->assertCreated();

        $deliveryOrderId = (int) $response->json('data.id');

        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/confirm")->assertOk();
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();

        $this->assertSame(76.0, $this->stockOnHand(), '2 Boxes must issue 24 stock units');
        $this->assertSame(24.0, $this->stockOut());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Quotation → sales order → invoice keeps the sales UoM end to end
    // ═══════════════════════════════════════════════════════════════════════

    public function test_quotation_selling_two_boxes_converts_to_stock_units_on_the_invoice(): void
    {
        $response = $this->apiPost('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => '2 boxes of widgets',
                'quantity' => 2,
                'uom_id' => $this->box->id,
                'unit_price' => 300,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();
        $quotationId = (int) $response->json('data.id');

        $quotationLine = \App\Models\Quotation::query()->findOrFail($quotationId)->lines()->first();
        $this->assertSame(2.0, (float) $quotationLine->quantity, 'A quotation line in Box stays 2 Box');
        $this->assertSame((int) $this->box->id, (int) $quotationLine->uom_id);
        $this->assertSame(300.0, (float) $quotationLine->unit_price, 'The quote price is per Box');

        $this->apiPost("/api/v1/quotations/{$quotationId}/confirm")->assertOk();

        $quotation = \App\Models\Quotation::query()->findOrFail($quotationId);
        $orderId = (int) $quotation->sales_order_id;
        $this->assertGreaterThan(0, $orderId);

        $orderLine = \Modules\Sales\Models\SalesOrder::query()->findOrFail($orderId)->lines()->first();
        $this->assertSame(24.0, (float) $orderLine->quantity, 'The order stores 24 stock units');
        $this->assertSame(25.0, (float) $orderLine->unit_price, 'The order stores the per-stock-unit price');

        $invoiceResponse = $this->apiPost("/api/v1/sales/orders/{$orderId}/invoice");
        $invoiceResponse->assertCreated();
        $invoiceId = (int) $invoiceResponse->json('invoice.id');

        $this->postInvoice($invoiceId);

        $this->assertSame(76.0, $this->stockOnHand(), '2 Boxes must issue 24 stock units');
        $this->assertSame(24.0, $this->stockOut());
        $this->assertSame(240.0, $this->cogsForDocument('invoice', $invoiceId));

        $invoiceLine = Invoice::query()->findOrFail($invoiceId)->lines()->first();
        $this->assertSame(2.0, (float) $invoiceLine->quantity, 'The invoice shows 2 Box');
        $this->assertSame('BX', $invoiceLine->unit);
        $this->assertSame(300.0, (float) $invoiceLine->unit_price, 'The invoice price is per Box');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Back-compat: a line without uom_id behaves exactly as today
    // ═══════════════════════════════════════════════════════════════════════

    public function test_invoice_without_uom_id_still_treats_quantity_as_stock_units(): void
    {
        $invoiceId = $this->directInvoice(5, null, 25);

        $this->postInvoice($invoiceId);

        $this->assertSame(95.0, $this->stockOnHand());
        $this->assertSame(5.0, $this->stockOut());
        $this->assertSame(50.0, $this->cogsForDocument('invoice', $invoiceId));

        $line = Invoice::query()->findOrFail($invoiceId)->lines()->first();
        $this->assertSame(5.0, (float) $line->quantity);
        $this->assertNull($line->uom_id);
    }
}
