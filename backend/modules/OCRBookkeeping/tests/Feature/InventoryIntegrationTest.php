<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Carbon\Carbon;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use Tests\TenantTestCase;

class InventoryIntegrationTest extends TenantTestCase
{
    protected User $user;

    protected OcrPostingService $service;

    protected Vendor $vendor;

    protected Product $product;

    protected ChartOfAccount $inventoryAccount;

    protected ChartOfAccount $payableAccount;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);

        // Create default warehouse (required by OcrPostingService::resolveWarehouseIdForItem)
        $this->warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'cost' => 50.00,
            'stock_quantity' => 10,
            'track_inventory' => true,
        ]);

        // Use seeded Inventory account (code 1200)
        $this->inventoryAccount = ChartOfAccount::where('code', '1200')->firstOrFail();
        $this->payableAccount = ChartOfAccount::where('code', '2100')->firstOrFail();

        // Create open fiscal period for today (FiscalPeriodValidator blocks dates without periods)
        $today = Carbon::today();
        FiscalPeriod::create([
            'name' => $today->format('F Y'),
            'code' => $today->format('Y-m'),
            'period_start' => $today->startOfMonth()->toDateString(),
            'period_end' => $today->copy()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => $today->year,
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);

        $this->service = app(OcrPostingService::class);
    }

    /**
     * Property 27: SKU matching creates stock movements
     */
    public function test_sku_matching_creates_stock_movements()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test Product',
                    'amount' => 100.00,
                    'quantity' => 2,
                    'sku' => 'PROD-001',
                    'account_id' => $this->inventoryAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document);

        // Verify stock movement created
        $this->assertGreaterThan($initialStockMovements, StockMovement::count());

        $stockMovement = StockMovement::latest('id')->first();
        $this->assertEquals($this->product->id, $stockMovement->product_id);
        $this->assertEquals(2, $stockMovement->quantity);
        $this->assertEquals('purchase', $stockMovement->type);
    }

    /**
     * Property 28: Stock movements update WAC
     */
    public function test_stock_movements_update_wac()
    {
        $initialWAC = $this->product->cost;
        $initialQty = $this->product->stock_quantity;

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 120.00,
            'line_items' => [
                [
                    'description' => 'Test Product',
                    'amount' => 120.00, // $60 per unit
                    'quantity' => 2,
                    'sku' => 'PROD-001',
                    'account_id' => $this->inventoryAccount->id,
                ],
            ],
            'subtotal' => 120.00,
            'tax_amount' => 0,
        ]);

        $this->service->postDocument($document);

        $this->product->refresh();

        // WAC should be recalculated
        // Old: 10 units @ $50 = $500
        // New: 2 units @ $60 = $120
        // Total: 12 units @ $620 = $51.67 per unit
        $expectedWAC = ($initialQty * $initialWAC + 2 * 60) / ($initialQty + 2);

        $this->assertEqualsWithDelta($expectedWAC, $this->product->cost, 0.01);
    }

    /**
     * Property 29: Inventory changes update ledger
     */
    public function test_inventory_changes_update_ledger()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test Product',
                    'amount' => 100.00,
                    'quantity' => 2,
                    'sku' => 'PROD-001',
                    'account_id' => $this->inventoryAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $this->service->postDocument($document);

        $document->refresh();
        $journalEntry = $document->journalEntry;

        $this->assertNotNull($journalEntry, 'Journal entry should be created');

        // Verify inventory account debited (line item account)
        $inventoryLine = $journalEntry->lines()
            ->where('account_id', $this->inventoryAccount->id)
            ->where('debit', '>', 0)
            ->first();

        $this->assertNotNull($inventoryLine);
        $this->assertEqualsWithDelta(100.00, (float) $inventoryLine->debit, 0.01);
    }

    /**
     * Property 30: Rollback cascades to inventory
     */
    public function test_rollback_cascades_to_inventory()
    {
        $initialQty = $this->product->stock_quantity;
        $initialWAC = $this->product->cost;
        $initialStockMovements = StockMovement::count();

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test Product',
                    'amount' => 100.00,
                    'quantity' => 2,
                    'sku' => 'PROD-001',
                    'account_id' => 99999, // Invalid account - will cause rollback
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        try {
            $this->service->postDocument($document);
        } catch (\Exception $e) {
            // Expected to fail — OcrPostingService wraps in DB::transaction internally
        }

        $this->product->refresh();

        // Inventory should not change due to rollback
        $this->assertEquals($initialQty, $this->product->stock_quantity);
        $this->assertEquals($initialStockMovements, StockMovement::count());
    }

    /**
     * Test: Non-inventory items don't create stock movements
     */
    public function test_non_inventory_items_dont_create_stock_movements()
    {
        $expenseAccount = ChartOfAccount::where('code', '5200')->firstOrFail();

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Office Supplies',
                    'amount' => 100.00,
                    'quantity' => 1,
                    // No SKU - should not create stock movement
                    'account_id' => $expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document);

        // No stock movement should be created
        $this->assertEquals($initialStockMovements, StockMovement::count());
    }

    /**
     * Test: Invalid SKU is handled gracefully
     *
     * OcrPostingService logs a warning and skips stock updates for unmatched SKUs.
     */
    public function test_invalid_sku_is_handled_gracefully()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Unknown Product',
                    'amount' => 100.00,
                    'quantity' => 1,
                    'sku' => 'INVALID-SKU',
                    'account_id' => $this->inventoryAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        // Should succeed — invalid SKU is logged and skipped, GL still posts
        $this->service->postDocument($document);

        // No stock movement for invalid SKU
        $this->assertEquals($initialStockMovements, StockMovement::count());

        $document->refresh();
        $this->assertEquals('POSTED', $document->status);
    }

    /**
     * Test: Multiple SKUs in one document
     */
    public function test_multiple_skus_in_one_document()
    {
        $product2 = Product::factory()->create([
            'sku' => 'PROD-002',
            'name' => 'Test Product 2',
            'cost' => 30.00,
            'stock_quantity' => 5,
            'track_inventory' => true,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 200.00,
            'line_items' => [
                [
                    'description' => 'Test Product 1',
                    'amount' => 100.00,
                    'quantity' => 2,
                    'sku' => 'PROD-001',
                    'account_id' => $this->inventoryAccount->id,
                ],
                [
                    'description' => 'Test Product 2',
                    'amount' => 100.00,
                    'quantity' => 3,
                    'sku' => 'PROD-002',
                    'account_id' => $this->inventoryAccount->id,
                ],
            ],
            'subtotal' => 200.00,
            'tax_amount' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document);

        // Should create 2 stock movements
        $this->assertEquals($initialStockMovements + 2, StockMovement::count());
    }

    /**
     * Test: Zero quantity doesn't create stock movement
     */
    public function test_zero_quantity_doesnt_create_stock_movement()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test Product',
                    'amount' => 100.00,
                    'quantity' => 0,
                    'sku' => 'PROD-001',
                    'account_id' => $this->inventoryAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document);

        // No stock movement for zero quantity
        $this->assertEquals($initialStockMovements, StockMovement::count());
    }
}
