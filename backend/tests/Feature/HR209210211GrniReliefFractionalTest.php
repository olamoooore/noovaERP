<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\VendorBillService;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Tests\TenantTestCase;

/**
 * Issues #209 / #210 / #211 (regressions from the #105 GRNI fix):
 *  #210 — GRN valuation truncated fractional quantity_accepted (int cast)
 *  #211 — bill-side GRNI relief truncated fractional quantity_accepted
 *  #209 — GRNI relief recomputed from the full receipt value on every bill,
 *         double-relieving across partial bills
 */
class HR209210211GrniReliefFractionalTest extends TenantTestCase
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
            'purchase_orders', 'goods_receipts', 'bills', 'journal_entries', 'journal_entry_lines',
        ]);

        $this->user = User::create([
            'name' => 'HR209 User',
            'email' => 'hr209-'.uniqid().'@test.local',
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
            'name' => 'HR209 Warehouse', 'code' => 'HR209-'.uniqid(), 'is_active' => true,
        ]);
        $this->supplier = Supplier::create([
            'name' => 'HR209 Supplier', 'code' => 'HR209-'.Str::upper(Str::random(6)), 'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'HR209 Widget', 'sku' => 'HR209-'.uniqid(),
            'cost' => 10.00, 'price' => 20.00, 'track_inventory' => true,
        ]);
        $this->expenseAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'subtype' => 'operating_expense']);
    }

    private function orderedPo(float $qty, float $unitCost): PurchaseOrder
    {
        $subtotal = $qty * $unitCost;

        $po = PurchaseOrder::create([
            'po_number' => 'PO-HR209-'.Str::upper(Str::random(6)),
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
            'description' => 'HR209 item',
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

    private function confirmedGrn(PurchaseOrder $po, float $acceptedQty): GoodsReceipt
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

    private function draftBill(GoodsReceipt $grn, PurchaseOrder $po, float $net, float $qty): Bill
    {
        $bill = Bill::create([
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $po->id,
            'goods_receipt_id' => $grn->id,
            'bill_type' => 'procurement',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => $net,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $net,
            'paid_amount' => 0,
            'balance' => $net,
            'created_by' => $this->user->id,
        ]);

        BillLine::create([
            'bill_id' => $bill->id,
            'description' => 'HR209 bill line',
            'quantity' => $qty,
            'unit_price' => $net / max($qty, 0.0001),
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => $net,
            'account_id' => $this->expenseAccount->id,
            'purchase_order_item_id' => $po->items->first()->id,
            'goods_receipt_line_id' => $grn->lines->first()->id,
        ]);

        return $bill->fresh(['lines', 'vendor']);
    }

    private function journalLines(string $documentType, int $documentId): \Illuminate\Support\Collection
    {
        $link = JournalEntryLink::where('source_document_type', $documentType)
            ->where('source_document_id', $documentId)
            ->first();

        $this->assertNotNull($link, "Expected a journal entry for {$documentType} #{$documentId}");

        return JournalEntryLine::where('journal_entry_id', $link->journal_entry_id)->get();
    }

    private function accountNet(int $accountId): float
    {
        $lines = JournalEntryLine::where('account_id', $accountId)->get();

        return round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);
    }

    // ─────────────────────────────────────────────────────────────────────
    // #210 — fractional receipt valuation
    // ─────────────────────────────────────────────────────────────────────

    public function test_receipt_valuation_does_not_truncate_fractional_quantity(): void
    {
        $po = $this->orderedPo(5, 10.00);
        $grn = $this->confirmedGrn($po, 4.75);

        $lines = $this->journalLines('goods_receipt', $grn->id);
        $mapping = app(AccountMappingService::class);

        $inventoryLine = $lines->firstWhere('account_id', $mapping->resolveId('inventory'));
        $grniLine = $lines->firstWhere('account_id', $mapping->resolveId('grni'));

        $this->assertSame(47.50, round((float) $inventoryLine->debit, 2), '4.75 x 10.00 must post 47.50, not 40.00');
        $this->assertSame(47.50, round((float) $grniLine->credit, 2));
    }

    // ─────────────────────────────────────────────────────────────────────
    // #211 — fractional bill-side relief
    // ─────────────────────────────────────────────────────────────────────

    public function test_bill_relief_does_not_truncate_fractional_quantity(): void
    {
        $po = $this->orderedPo(5, 10.00);
        $grn = $this->confirmedGrn($po, 4.75);

        $bill = $this->draftBill($grn, $po, 47.50, 4.75);
        $result = app(VendorBillService::class)->postBill($bill);
        $this->assertTrue($result->success, 'Bill posting failed: '.($result->error ?? ''));

        $lines = $this->journalLines('bill', $bill->id);
        $mapping = app(AccountMappingService::class);

        $grniDebit = round((float) $lines->where('account_id', $mapping->resolveId('grni'))->sum('debit'), 2);
        $this->assertSame(47.50, $grniDebit, 'Relief must use 4.75 units (47.50), not 4 (40.00)');
    }

    // ─────────────────────────────────────────────────────────────────────
    // #209 — partial bills must not double-relieve GRNI
    // ─────────────────────────────────────────────────────────────────────

    public function test_partial_bills_do_not_double_relieve_grni(): void
    {
        $po = $this->orderedPo(10, 10.00);
        $grn = $this->confirmedGrn($po, 10); // receipt value 100.00 -> CR GRNI 100

        $billService = app(VendorBillService::class);
        $mapping = app(AccountMappingService::class);
        $grniId = $mapping->resolveId('grni');

        // Bill 1: nets 70 -> relief 70 (GRNI left 30).
        $bill1 = $this->draftBill($grn, $po, 70.00, 7);
        $this->assertTrue($billService->postBill($bill1)->success);

        // Bill 2: nets 50 (30 remaining goods + 20 freight) -> relief must be 30.
        $bill2 = $this->draftBill($grn, $po, 50.00, 3);
        $result = $billService->postBill($bill2);
        $this->assertTrue($result->success, 'Bill posting failed: '.($result->error ?? ''));

        $relief1 = round((float) $this->journalLines('bill', $bill1->id)->where('account_id', $grniId)->sum('debit'), 2);
        $relief2 = round((float) $this->journalLines('bill', $bill2->id)->where('account_id', $grniId)->sum('debit'), 2);

        $this->assertSame(70.0, $relief1);
        $this->assertSame(30.0, $relief2, 'Second partial bill must relieve only the remaining 30.00, not 50.00');

        // GRNI nets to zero across receipt + bills; no stray negative balance.
        $this->assertSame(0.0, $this->accountNet($grniId), 'GRNI must be exactly fully relieved');
    }

    public function test_fully_relieved_receipt_yields_no_further_relief(): void
    {
        $po = $this->orderedPo(10, 10.00);
        $grn = $this->confirmedGrn($po, 10);

        $billService = app(VendorBillService::class);
        $mapping = app(AccountMappingService::class);
        $grniId = $mapping->resolveId('grni');

        // Bill 1 relieves the full 100.
        $bill1 = $this->draftBill($grn, $po, 100.00, 10);
        $this->assertTrue($billService->postBill($bill1)->success);

        // A later bill against the same (fully relieved) GRN must not debit GRNI.
        $bill2 = $this->draftBill($grn, $po, 25.00, 1);
        $this->assertTrue($billService->postBill($bill2)->success);

        $relief2 = round((float) $this->journalLines('bill', $bill2->id)->where('account_id', $grniId)->sum('debit'), 2);
        $this->assertSame(0.0, $relief2);
        $this->assertSame(0.0, $this->accountNet($grniId));
    }
}
