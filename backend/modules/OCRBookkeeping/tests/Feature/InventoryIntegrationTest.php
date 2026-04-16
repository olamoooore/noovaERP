<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\User;
use Carbon\Carbon;

class InventoryIntegrationTest extends TenantTestCase
{
    protected User $user;
    protected OcrPostingService $service;
    protected Vendor $vendor;
    protected Product $product;
    protected ChartOfAccount $inventoryAccount;
    protected ChartOfAccount $payableAccount;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);
        
        $this->product = Product::factory()->create([
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'unit_cost' => 50.00,
            'quantity_on_hand' => 10,
        ]);
        
        $this->inventoryAccount = ChartOfAccount::factory()->create([
            'code' => '1300',
            'name' => 'Inventory',
            'account_type' => 'asset',
        ]);
        
        $this->payableAccount = ChartOfAccount::factory()->create([
            'code' => '2100',
            'name' => 'Accounts Payable',
            'account_type' => 'liability',
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
            'tax_rate' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document, $this->user->id);

        // Verify stock movement created
        $this->assertEquals($initialStockMovements + 1, StockMovement::count());
        
        $stockMovement = StockMovement::latest()->first();
        $this->assertEquals($this->product->id, $stockMovement->product_id);
        $this->assertEquals(2, $stockMovement->quantity);
        $this->assertEquals('purchase', $stockMovement->movement_type);
    }

    /**
     * Property 28: Stock movements update WAC
     */
    public function test_stock_movements_update_wac()
    {
        $initialWAC = $this->product->unit_cost;
        $initialQty = $this->product->quantity_on_hand;

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
            'tax_rate' => 0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $this->product->refresh();

        // WAC should be recalculated
        // Old: 10 units @ $50 = $500
        // New: 2 units @ $60 = $120
        // Total: 12 units @ $620 = $51.67 per unit
        $expectedWAC = ($initialQty * $initialWAC + 2 * 60) / ($initialQty + 2);
        
        $this->assertEquals($expectedWAC, $this->product->unit_cost, '', 0.01);
        $this->assertEquals($initialQty + 2, $this->product->quantity_on_hand);
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
            'tax_rate' => 0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $document->refresh();
        $journalEntry = $document->journalEntry;

        // Verify inventory account debited
        $inventoryLine = $journalEntry->lines()
            ->where('account_id', $this->inventoryAccount->id)
            ->where('debit_credit', 'debit')
            ->first();

        $this->assertNotNull($inventoryLine);
        $this->assertEquals(100.00, $inventoryLine->amount);
    }

    /**
     * Property 30: Rollback cascades to inventory
     */
    public function test_rollback_cascades_to_inventory()
    {
        $initialQty = $this->product->quantity_on_hand;
        $initialWAC = $this->product->unit_cost;
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
        ]);

        try {
            \DB::transaction(function () use ($document) {
                $this->service->postDocument($document, $this->user->id);
            });
        } catch (\Exception $e) {
            // Expected to fail
        }

        $this->product->refresh();

        // Inventory should not change
        $this->assertEquals($initialQty, $this->product->quantity_on_hand);
        $this->assertEquals($initialWAC, $this->product->unit_cost);
        $this->assertEquals($initialStockMovements, StockMovement::count());
    }

    /**
     * Test: Non-inventory items don't create stock movements
     */
    public function test_non_inventory_items_dont_create_stock_movements()
    {
        $expenseAccount = ChartOfAccount::factory()->create([
            'code' => '5200',
            'name' => 'Operating Expenses',
            'account_type' => 'expense',
        ]);

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
                    // No SKU
                    'account_id' => $expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'tax_rate' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document, $this->user->id);

        // No stock movement should be created
        $this->assertEquals($initialStockMovements, StockMovement::count());
    }

    /**
     * Test: Invalid SKU is handled gracefully
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
            'tax_rate' => 0,
        ]);

        // Should either skip or throw exception
        try {
            $this->service->postDocument($document, $this->user->id);
            
            // If it succeeds, verify no stock movement created
            $stockMovement = StockMovement::where('product_id', null)->first();
            $this->assertNull($stockMovement);
            
        } catch (\Exception $e) {
            // Expected - invalid SKU should cause error
            $this->assertStringContainsString('SKU', $e->getMessage());
        }
    }

    /**
     * Test: Multiple SKUs in one document
     */
    public function test_multiple_skus_in_one_document()
    {
        $product2 = Product::factory()->create([
            'sku' => 'PROD-002',
            'name' => 'Test Product 2',
            'unit_cost' => 30.00,
            'quantity_on_hand' => 5,
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
            'tax_rate' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document, $this->user->id);

        // Should create 2 stock movements
        $this->assertEquals($initialStockMovements + 2, StockMovement::count());
        
        // Verify both products updated
        $this->product->refresh();
        $product2->refresh();
        
        $this->assertEquals(12, $this->product->quantity_on_hand);
        $this->assertEquals(8, $product2->quantity_on_hand);
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
            'tax_rate' => 0,
        ]);

        $initialStockMovements = StockMovement::count();

        $this->service->postDocument($document, $this->user->id);

        // No stock movement for zero quantity
        $this->assertEquals($initialStockMovements, StockMovement::count());
    }
}
