<?php

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\BillService;
use App\Services\GoodsReceiptService;
use App\Services\VendorBillService;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Tests\TenantTestCase;

/**
 * Issue #105 (BLOCKER) — confirming a goods receipt must post
 * DR Inventory / CR GRNI through PostingEngine, and the matching Vendor Bill
 * must relieve GRNI (DR GRNI / CR AP) instead of double-counting inventory.
 *
 * Verification from the issue: receive a GRN for a PO with a known unit cost,
 * assert a journal entry debits Inventory and credits GRNI for the correct
 * amount, and assert the Inventory GL balance increases by exactly that amount.
 */
class HR105GoodsReceiptGlPostingTest extends TenantTestCase
{
    private User $user;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Product $product;

    private ChartOfAccount $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($tenantId);

        $this->assertTenantSchemaReady([
            'purchase_orders', 'purchase_order_items', 'goods_receipts',
            'goods_receipt_lines', 'bills', 'journal_entries', 'journal_entry_lines',
        ]);

        $this->user = User::create([
            'name' => 'HR105 User',
            'email' => 'hr105-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user);

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
                'created_by' => $this->user->id,
            ]
        );

        $this->vendor = Vendor::factory()->create();
        $this->warehouse = Warehouse::create([
            'name' => 'HR105 Warehouse',
            'code' => 'HR105-'.uniqid(),
            'is_active' => true,
        ]);
        $this->supplier = Supplier::create([
            'name' => 'HR105 Supplier',
            'code' => 'HR105-'.Str::upper(Str::random(6)),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'HR105 Widget',
            'sku' => 'HR105-'.uniqid(),
            'cost' => 15.00,
            'price' => 25.00,
            'track_inventory' => true,
        ]);
        $this->expenseAccount = ChartOfAccount::factory()->create([
            'type' => 'expense',
            'subtype' => 'operating_expense',
        ]);
    }

    private function orderedPo(int $qty = 4, float $unitCost = 15.00): PurchaseOrder
    {
        $subtotal = $qty * $unitCost;

        $po = PurchaseOrder::create([
            'po_number' => 'PO-HR105-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => $subtotal,
            'currency' => 'USD',
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'HR105 item',
            'quantity_ordered' => $qty,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => $subtotal,
        ]);

        return $po->fresh('items');
    }

    private function confirmedGrn(PurchaseOrder $po, int $acceptedQty): GoodsReceipt
    {
        $service = app(GoodsReceiptService::class);

        $grn = $service->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_item_id' => $po->items->first()->id,
                'quantity_received' => $acceptedQty,
                'quantity_accepted' => $acceptedQty,
                'quantity_rejected' => 0,
            ]],
        ]);

        return $service->confirm($grn);
    }

    private function journalEntryLines(string $documentType, int $documentId): \Illuminate\Support\Collection
    {
        $link = JournalEntryLink::where('source_document_type', $documentType)
            ->where('source_document_id', $documentId)
            ->first();

        $this->assertNotNull($link, "Expected a journal entry linked to {$documentType} #{$documentId}");

        return JournalEntryLine::where('journal_entry_id', $link->journal_entry_id)->get();
    }

    private function accountNet(int $accountId): float
    {
        $lines = JournalEntryLine::where('account_id', $accountId)->get();

        return round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Receipt posting (issue verification)
    // ─────────────────────────────────────────────────────────────────────

    public function test_confirmed_goods_receipt_posts_inventory_and_grni(): void
    {
        $po = $this->orderedPo(4, 15.00);
        $grn = $this->confirmedGrn($po, 4);

        $lines = $this->journalEntryLines('goods_receipt', $grn->id);
        $this->assertCount(2, $lines);

        $mapping = app(AccountMappingService::class);
        $inventoryId = $mapping->resolveId('inventory');
        $grniId = $mapping->resolveId('grni');

        $inventoryLine = $lines->firstWhere('account_id', $inventoryId);
        $grniLine = $lines->firstWhere('account_id', $grniId);

        $this->assertNotNull($inventoryLine, 'Receipt must debit Inventory');
        $this->assertNotNull($grniLine, 'Receipt must credit GRNI');
        $this->assertSame(60.00, round((float) $inventoryLine->debit, 2));
        $this->assertSame(0.00, round((float) $inventoryLine->credit, 2));
        $this->assertSame(60.00, round((float) $grniLine->credit, 2));
        $this->assertSame(0.00, round((float) $grniLine->debit, 2));

        // GL balance movement: Inventory +60, GRNI liability +60.
        $this->assertSame(60.00, $this->accountNet($inventoryId), 'Inventory GL must increase by exactly the receipt value');
        $this->assertSame(-60.00, $this->accountNet($grniId), 'GRNI liability must increase by exactly the receipt value');
    }

    public function test_zero_cost_receipt_posts_nothing(): void
    {
        $po = $this->orderedPo(3, 0.00);
        $grn = $this->confirmedGrn($po, 3);

        $this->assertFalse(
            JournalEntryLink::where('source_document_type', 'goods_receipt')
                ->where('source_document_id', $grn->id)
                ->exists(),
            'A zero-valued receipt must not create an empty journal entry'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bill-side GRNI relief (no double counting)
    // ─────────────────────────────────────────────────────────────────────

    public function test_matched_bill_relieves_grni_instead_of_expensing_inventory_again(): void
    {
        $po = $this->orderedPo(4, 15.00);
        $grn = $this->confirmedGrn($po, 4);

        $bill = app(BillService::class)->createBill([
            'vendor_id' => $this->vendor->id,
            'goods_receipt_id' => $grn->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'account_id' => $this->expenseAccount->id,
                'description' => 'HR105 item',
                'quantity' => 4,
                'unit_price' => 15.00,
                'purchase_order_item_id' => $po->items->first()->id,
                'goods_receipt_line_id' => $grn->lines->first()->id,
            ]],
        ], $this->user->id);

        $this->assertSame('draft', $bill->status);

        $result = app(VendorBillService::class)->postBill($bill->fresh());
        $this->assertTrue($result->success, 'Bill posting failed: '.($result->error ?? ''));

        $lines = $this->journalEntryLines('bill', $bill->id);
        $mapping = app(AccountMappingService::class);
        $inventoryId = $mapping->resolveId('inventory');
        $grniId = $mapping->resolveId('grni');
        $apId = $mapping->resolveId('accounts_payable');

        $grniDebit = round((float) $lines->where('account_id', $grniId)->sum('debit'), 2);
        $apCredit = round((float) $lines->where('account_id', $apId)->sum('credit'), 2);
        $expenseDebit = round((float) $lines->where('account_id', $this->expenseAccount->id)->sum('debit'), 2);

        $this->assertSame(60.00, $grniDebit, 'Matched bill must debit GRNI for the receipt value');
        $this->assertSame(60.00, $apCredit);
        $this->assertSame(0.00, $expenseDebit, 'Matched receipt value must NOT be expensed again');

        // No double counting: Inventory stays at +60 and GRNI nets to zero.
        $this->assertSame(60.00, $this->accountNet($inventoryId));
        $this->assertSame(0.00, $this->accountNet($grniId), 'GRNI must be fully relieved by the matched bill');
    }

    public function test_bill_above_receipt_value_expenses_only_the_difference(): void
    {
        $po = $this->orderedPo(4, 15.00);
        $grn = $this->confirmedGrn($po, 4);

        // Created directly (not via BillService) because the three-way match
        // rejects any price above the PO price at zero tolerance — this test
        // isolates the posting-side relief cap rather than the match policy.
        $bill = \App\Models\Bill::create([
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $po->id,
            'goods_receipt_id' => $grn->id,
            'bill_type' => 'procurement',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 80.00,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 80.00,
            'paid_amount' => 0,
            'balance' => 80.00,
            'created_by' => $this->user->id,
        ]);

        \App\Models\BillLine::create([
            'bill_id' => $bill->id,
            'description' => 'HR105 item (price increased)',
            'quantity' => 4,
            'unit_price' => 20.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 80.00,
            'account_id' => $this->expenseAccount->id,
            'purchase_order_item_id' => $po->items->first()->id,
            'goods_receipt_line_id' => $grn->lines->first()->id,
        ]);

        $result = app(VendorBillService::class)->postBill($bill->fresh(['lines', 'vendor']));
        $this->assertTrue($result->success, 'Bill posting failed: '.($result->error ?? ''));

        $lines = $this->journalEntryLines('bill', $bill->id);
        $mapping = app(AccountMappingService::class);

        $grniDebit = round((float) $lines->where('account_id', $mapping->resolveId('grni'))->sum('debit'), 2);
        $expenseDebit = round((float) $lines->where('account_id', $this->expenseAccount->id)->sum('debit'), 2);
        $apCredit = round((float) $lines->where('account_id', $mapping->resolveId('accounts_payable'))->sum('credit'), 2);

        $this->assertSame(60.00, $grniDebit, 'GRNI relief is capped at the receipt value');
        $this->assertSame(20.00, $expenseDebit, 'Only the excess above the receipt value is expensed');
        $this->assertSame(80.00, $apCredit);
    }

    public function test_direct_bill_without_receipt_is_unchanged(): void
    {
        $bill = app(BillService::class)->createBill([
            'vendor_id' => $this->vendor->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'account_id' => $this->expenseAccount->id,
                'description' => 'Office supplies',
                'quantity' => 2,
                'unit_price' => 50.00,
            ]],
        ], $this->user->id);

        $result = app(VendorBillService::class)->postBill($bill->fresh());
        $this->assertTrue($result->success, 'Bill posting failed: '.($result->error ?? ''));

        $lines = $this->journalEntryLines('bill', $bill->id);
        $mapping = app(AccountMappingService::class);

        $this->assertSame(100.00, round((float) $lines->where('account_id', $this->expenseAccount->id)->sum('debit'), 2));
        $this->assertSame(100.00, round((float) $lines->where('account_id', $mapping->resolveId('accounts_payable'))->sum('credit'), 2));
        $this->assertSame(0.00, round((float) $lines->where('account_id', $mapping->resolveId('grni'))->sum('debit'), 2), 'A non-procurement bill must not touch GRNI');
    }
}
