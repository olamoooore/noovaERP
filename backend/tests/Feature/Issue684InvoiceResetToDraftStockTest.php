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
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #684 [SALES-INV] P1 (IN4) — reset-to-draft reversed the invoice's
 * journal entry (including COGS) but never returned the stock posting deducted
 * (`reference_type = 'Invoice'`). While the invoice stayed a draft the stock
 * was out with no COGS, and deleting or re-editing the draft lost it for good:
 * the "already deducted" check on a repost only stops further deductions, it
 * never gives the excess back.
 *
 * Every test below drives the REAL routes (router → middleware → controller):
 * `POST /api/v1/invoices/{id}/post`, `POST /api/v1/invoices/{id}/reset-to-draft`,
 * `POST /api/v1/invoices/{id}/cancel` and `DELETE /api/v1/invoices/{id}`. The
 * business rules asserted are the issue's: after post → reset → delete the
 * warehouse is back at its opening level, repeated reset → repost → reset
 * cycles never return more stock than was issued, and goods a delivery order
 * shipped are never conjured back.
 */
class Issue684InvoiceResetToDraftStockTest extends TenantTestCase
{
    private const UNIT_COST = 10.00;

    private const OPENING_STOCK = 100;

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
            'name' => 'Invoice Reset Stock Tenant',
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

        // Posting and reversing need an open accounting period.
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
            'name' => 'Reset Stock Warehouse',
            'code' => 'RSW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Reset Stock Widget',
            'sku' => 'RSW-'.uniqid(),
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

    /** Create a direct (invoice-form) draft invoice for $quantity units. */
    private function draftDirectInvoice(float $quantity): int
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
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function postInvoice(int $invoiceId): void
    {
        $this->apiPost("/api/v1/invoices/{$invoiceId}/post")->assertOk();
    }

    /** Post a direct invoice for $quantity units and return the fresh model. */
    private function postedDirectInvoice(float $quantity): Invoice
    {
        $invoiceId = $this->draftDirectInvoice($quantity);
        $this->postInvoice($invoiceId);

        return Invoice::query()->findOrFail($invoiceId);
    }

    /** Total quantity of the product that left the warehouse as a sale. */
    private function stockOut(): float
    {
        return (float) StockMovement::where('type', 'sale')
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    /** Total quantity of the product returned to the warehouse. */
    private function stockReturned(): float
    {
        return (float) StockMovement::where('type', 'return')
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    private function stockOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    /** Return movements the invoice itself is linked to. */
    private function invoiceReturnMovements(Invoice $invoice): Collection
    {
        return StockMovement::where('type', 'return')
            ->where('reference_type', 'Invoice')
            ->where('reference_id', $invoice->id)
            ->orderBy('id')
            ->get();
    }

    /** Net COGS recognised on the GL, across every document. */
    private function cogsRecognised(): float
    {
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');

        return round(
            (float) JournalEntryLine::where('account_id', $cogsAccountId)->sum('debit')
            - (float) JournalEntryLine::where('account_id', $cogsAccountId)->sum('credit'),
            2
        );
    }

    /** The invoice's own journal entry must have been reversed by the reset. */
    private function assertInvoiceJournalReversed(Invoice $invoice): void
    {
        $this->assertNotNull(
            JournalEntryLink::where('source_document_type', 'invoice')
                ->where('source_document_id', $invoice->id)
                ->where('relation_type', 'reversal')
                ->first(),
            'Reset must create a reversal link for the invoice journal entry'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The core rule: reset returns the stock posting deducted; delete keeps it
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reset_to_draft_returns_invoice_issued_stock_and_delete_keeps_it_returned(): void
    {
        $invoice = $this->postedDirectInvoice(10);

        // Fixture: posting is real — 10 units issued, COGS 100, JE posted.
        $this->assertSame(10.0, $this->stockOut(), 'Fixture: posting issued 10 units');
        $this->assertSame(90.0, $this->stockOnHand());
        $this->assertSame(100.0, $this->cogsRecognised(), 'Fixture: posting recognised COGS of 10 x 10.00');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();

        $invoice->refresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->journal_entry_id, 'Reset clears the invoice journal entry link');

        $this->assertInvoiceJournalReversed($invoice);
        $this->assertSame(0.0, $this->cogsRecognised(), 'The reversal must release the COGS the reset reversed');

        // The stock posting deducted is back — a movement linked to the invoice.
        $returns = $this->invoiceReturnMovements($invoice);
        $this->assertCount(1, $returns, 'Reset must create one return movement per product the invoice issued');
        $this->assertSame((int) $invoice->id, (int) $returns->first()->reference_id);
        $this->assertSame(10.0, (float) $returns->first()->quantity);
        $this->assertSame(
            10.0,
            $this->stockReturned(),
            'Reset must return exactly the 10 units the invoice issued'
        );
        $this->assertSame(
            100.0,
            $this->stockOnHand(),
            'Stock on hand must be back to the opening 100 once the draft is reset'
        );

        // The reset draft is deleted: the returned stock must survive it.
        $this->actingAs($this->admin)->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertNull(Invoice::find($invoice->id), 'Fixture: the draft was deleted');
        $this->assertSame(
            100.0,
            $this->stockOnHand(),
            'Deleting a reset draft must not lose the stock the reset returned'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Idempotency — reset → repost → reset and cancel → reset
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reset_stock_return_is_idempotent_across_repost_cycles(): void
    {
        $invoice = $this->postedDirectInvoice(10);

        $this->apiPost("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();
        $this->assertSame(100.0, $this->stockOnHand(), 'First reset returns the 10 issued units');

        // Repost the same invoice, then reset it again.
        $this->postInvoice($invoice->id);
        $this->assertSame('posted', $invoice->fresh()->status, 'Fixture: the draft reposts through the real route');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();

        $this->assertSame(
            100.0,
            $this->stockOnHand(),
            'The second reset must leave the warehouse at the opening level'
        );
        $this->assertSame(
            0.0,
            $this->stockOut() - $this->stockReturned(),
            'Stock left out for the invoice must be zero: reset must never return more than was issued'
        );
        $this->assertSame(
            1,
            $this->invoiceReturnMovements($invoice)->count(),
            'The same issued units must be returned once, not once per reset'
        );
    }

    public function test_reset_after_cancel_does_not_return_the_stock_twice(): void
    {
        $invoice = $this->postedDirectInvoice(10);

        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();
        $this->assertSame(100.0, $this->stockOnHand(), 'Fixture: cancelling returned the 10 issued units');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(
            100.0,
            $this->stockOnHand(),
            'Cancel already returned the stock; reset must not put it back a second time'
        );
        $this->assertSame(
            10.0,
            $this->stockReturned(),
            'The 10 issued units must be returned exactly once across cancel and reset'
        );
        $this->assertSame(
            0.0,
            $this->stockOut() - $this->stockReturned(),
            'Stock left out for the invoice must be zero after cancel then reset'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Goods a delivery order shipped are never conjured back by a reset
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reset_to_draft_does_not_restock_goods_a_delivery_order_issued(): void
    {
        // SO -> complete delivery (issues stock + COGS) -> invoice from SO.
        $orderResponse = $this->apiPost('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 10,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $orderResponse->assertCreated();
        $orderId = (int) $orderResponse->json('id');

        \Modules\Sales\Models\SalesOrder::whereKey($orderId)
            ->update(['warehouse_id' => $this->warehouse->id]);
        $this->apiPost("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        $order = \Modules\Sales\Models\SalesOrder::with('lines')->findOrFail($orderId);
        $deliveryResponse = $this->apiPost('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $orderId,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => 10,
            ]],
        ]);
        $deliveryResponse->assertCreated();
        $deliveryOrderId = (int) $deliveryResponse->json('data.id');
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();

        $invoiceResponse = $this->apiPost("/api/v1/sales/orders/{$orderId}/invoice");
        $invoiceResponse->assertCreated();
        $invoice = Invoice::query()->findOrFail((int) $invoiceResponse->json('invoice.id'));
        $this->postInvoice($invoice->id);

        $this->assertSame(10.0, $this->stockOut(), 'Fixture: the delivery issued the 10 units');
        $this->assertSame(90.0, $this->stockOnHand());

        $this->apiPost("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(
            0.0,
            $this->stockReturned(),
            'The invoice issued no stock, so a reset must return none'
        );
        $this->assertSame(
            90.0,
            $this->stockOnHand(),
            'Stock must stay out: the goods were shipped by the delivery order, not the invoice'
        );
    }
}
