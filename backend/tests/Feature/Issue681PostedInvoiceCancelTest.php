<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Enums\UserRole;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #681 [SALES-INV] P0 (IN1) — cancelling a posted invoice only flipped
 * its status: the journal entry stayed posted (revenue, AR, output tax, COGS),
 * the stock the invoice issued was never returned, and the route asked for the
 * sales right `invoices.write` although cancelling releases accounting state.
 *
 * Every test below drives the REAL routes (router → middleware → controller):
 * `POST /api/v1/invoices/{id}/cancel`, the data check endpoint, and the
 * accountant/manager/sales permission split. The business rule asserted is the
 * issue's: after cancelling a posted invoice its GL revenue/AR/tax/COGS net to
 * zero, the stock is back on hand, and only `accounting.post` holders may do it.
 */
class Issue681PostedInvoiceCancelTest extends TenantTestCase
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
            'name' => 'Invoice Cancel Tenant',
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
            'name' => 'Cancel Warehouse',
            'code' => 'CW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Cancel Widget',
            'sku' => 'CW-'.uniqid(),
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
    private function draftDirectInvoice(float $quantity, float $taxRate = 0): int
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => $taxRate,
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

    private function postedInvoice(float $quantity, float $taxRate = 0): Invoice
    {
        $invoiceId = $this->draftDirectInvoice($quantity, $taxRate);
        $this->postInvoice($invoiceId);

        return Invoice::query()->findOrFail($invoiceId);
    }

    /**
     * Create a role-scoped tenant user (central user_company_access + the
     * tenant Spatie role) so RequirePermission / EnsureAccountingWriteAccess
     * resolve the same way they do in production.
     */
    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $user->assignRole($role->spatieName());

        return $user;
    }

    /** Net debit − credit for one account role across the invoice's post + reversal JEs. */
    private function invoiceJournalNet(Invoice $invoice, string $accountRole): float
    {
        $accountId = app(AccountMappingService::class)->resolveId($accountRole);

        $entryIds = array_values(array_filter([
            $invoice->journal_entry_id,
            JournalEntryLink::where('source_document_type', 'invoice')
                ->where('source_document_id', $invoice->id)
                ->where('relation_type', 'reversal')
                ->value('journal_entry_id'),
        ]));

        if ($entryIds === []) {
            return 0.0;
        }

        return round(
            (float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)->where('account_id', $accountId)->sum('debit')
            - (float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)->where('account_id', $accountId)->sum('credit'),
            2
        );
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

    private function stockOut(): float
    {
        return (float) StockMovement::where('type', 'sale')
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

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

    private function reservedOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('reserved_quantity');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The core rule: cancel reverses the ledger and returns the stock
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cancel_of_posted_invoice_reverses_the_journal_and_returns_the_stock(): void
    {
        // 10 units @ 25 with 10% output tax: AR 275, revenue 250, tax 25, COGS 100.
        $invoice = $this->postedInvoice(10, 10);

        // Fixture: the posting is real — 10 units issued, COGS 100, AR 275.
        $this->assertSame(10.0, $this->stockOut());
        $this->assertSame(90.0, $this->stockOnHand());
        $this->assertSame(275.0, $this->invoiceJournalNet($invoice, 'accounts_receivable'));
        $this->assertSame(-250.0, $this->invoiceJournalNet($invoice, 'sales_revenue'));
        $this->assertSame(-25.0, $this->invoiceJournalNet($invoice, 'sales_tax_payable'));
        $this->assertSame(100.0, $this->invoiceJournalNet($invoice, 'cogs'));
        $this->assertSame(100.0, $this->cogsRecognised());

        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();

        $invoice->refresh();

        $this->assertSame('cancelled', $invoice->status);
        $this->assertSame(
            'cancelled',
            $invoice->lifecycle_status,
            'A cancelled invoice must leave the posted lifecycle so reports stop counting it'
        );

        // The journal entry is reversed: Revenue / AR / output tax / COGS net to zero.
        $this->assertNotNull(
            JournalEntryLink::where('source_document_type', 'invoice')
                ->where('source_document_id', $invoice->id)
                ->where('relation_type', 'reversal')
                ->first(),
            'Cancelling must create a reversal link for the invoice journal entry'
        );
        foreach (['accounts_receivable', 'sales_revenue', 'sales_tax_payable', 'cogs', 'inventory'] as $accountRole) {
            $this->assertSame(
                0.0,
                $this->invoiceJournalNet($invoice, $accountRole),
                "GL account role '{$accountRole}' must net to zero after cancel"
            );
        }
        $this->assertSame(0.0, $this->cogsRecognised(), 'COGS must be reversed with the cancelled sale');

        // The stock the invoice issued is back.
        $this->assertSame(10.0, $this->stockReturned(), 'The invoice must return the 10 units it issued');
        $this->assertSame(100.0, $this->stockOnHand(), 'Stock on hand must be back to the opening 100');

        // The AR subledger and the customer stats drop the invoice too.
        $this->assertSame(
            0.0,
            (float) $this->customer->fresh()->total_outstanding,
            'A cancelled invoice must not stay in the customer AR balance'
        );
    }

    public function test_cancel_restores_sales_order_invoiced_qty_and_releases_the_reservation(): void
    {
        $invoice = $this->postedInvoice(10);

        $soLine = \Modules\Sales\Models\SalesOrderLine::where('sales_order_id', $invoice->sales_order_id)->firstOrFail();

        $this->assertSame(10.0, (float) $soLine->quantity_invoiced, 'Fixture: the invoice billed 10 ordered units');
        $this->assertSame(10.0, $this->reservedOnHand(), 'Fixture: confirming the order reserved 10 units');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();

        $this->assertSame(
            0.0,
            (float) $soLine->fresh()->quantity_invoiced,
            'Cancelling must give the invoiced quantity back so the order can be billed again'
        );

        $this->assertSame(
            0.0,
            $this->reservedOnHand(),
            'The reservation this sale never shipped must be released with the cancellation'
        );
        $this->assertSame(
            0,
            StockReservation::where('source_document_type', 'SalesOrder')
                ->where('source_document_id', $invoice->sales_order_id)
                ->whereIn('status', ['open', 'allocated'])
                ->count(),
            'No reservation may stay active for a cancelled invoice'
        );
    }

    /**
     * When a completed delivery order issued the goods, cancelling the invoice
     * must not conjure stock back: the goods physically shipped. Only the
     * invoice's own revenue/AR/tax posting is reversed.
     */
    public function test_cancel_does_not_restock_goods_a_delivery_order_issued(): void
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

        $this->assertSame(10.0, $this->stockOut(), 'The delivery issued the 10 units');
        $this->assertSame(90.0, $this->stockOnHand());
        $this->assertSame(100.0, $this->cogsRecognised(), 'The delivery recognised the COGS');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();

        $invoice->refresh();

        $this->assertSame('cancelled', $invoice->status);

        // Revenue / AR / tax reverse; the delivered COGS stays with the goods.
        foreach (['accounts_receivable', 'sales_revenue', 'sales_tax_payable'] as $accountRole) {
            $this->assertSame(
                0.0,
                $this->invoiceJournalNet($invoice, $accountRole),
                "GL account role '{$accountRole}' must net to zero after cancel"
            );
        }
        $this->assertSame(100.0, $this->cogsRecognised(),
            'COGS must stay recognised — the delivery shipped the goods, the invoice never booked COGS');

        // No phantom restock: the delivery owns the goods, not the invoice.
        $this->assertSame(0.0, $this->stockReturned(),
            'The invoice issued no stock, so it must return none');
        $this->assertSame(90.0, $this->stockOnHand(),
            'Stock must stay out: the goods were delivered');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Cancelling is an accounting action: accounting.post is required
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cancelling_a_posted_invoice_requires_accounting_post(): void
    {
        $sales = $this->actingAsRole(UserRole::SALES);
        $manager = $this->actingAsRole(UserRole::MANAGER);
        $accountant = $this->actingAsRole(UserRole::ACCOUNTANT);

        $invoice = $this->postedInvoice(1);

        // Sales may view but must not release accounting state.
        $this->actingAs($sales)->postJson("/api/v1/invoices/{$invoice->id}/cancel")
            ->assertStatus(403);

        // Manager holds accounting.write (and invoices.write) but not accounting.post.
        $this->actingAs($manager)->postJson("/api/v1/invoices/{$invoice->id}/cancel")
            ->assertStatus(403);

        $this->assertSame('posted', $invoice->fresh()->status, 'Denied cancels must not mutate the invoice');
        $this->assertNull(
            JournalEntryLink::where('source_document_type', 'invoice')
                ->where('source_document_id', $invoice->id)
                ->where('relation_type', 'reversal')
                ->first(),
            'Denied cancels must not reverse the journal entry'
        );

        // Positive control: the accountant holds accounting.post and may cancel.
        $this->actingAs($accountant)->postJson("/api/v1/invoices/{$invoice->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertNotNull(
            JournalEntryLink::where('source_document_type', 'invoice')
                ->where('source_document_id', $invoice->id)
                ->where('relation_type', 'reversal')
                ->first(),
            'The accountant cancellation must reverse the journal entry'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The issue's data check: cancelled invoices with a live GL entry
    // ═══════════════════════════════════════════════════════════════════════

    public function test_data_check_reports_cancelled_invoices_whose_journal_was_not_reversed(): void
    {
        // Historical bad data: cancelled in NoovaERP, GL entry left posted.
        $bad = $this->postedInvoice(10);
        $bad->update(['status' => 'cancelled', 'lifecycle_status' => 'cancelled']);

        // Correct flow: cancel through the route, which reverses the JE.
        $good = $this->postedInvoice(4);
        $this->apiPost("/api/v1/invoices/{$good->id}/cancel")->assertOk();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/invoices/data-checks/cancelled-without-reversal');
        $response->assertOk();

        $reportedIds = array_column($response->json('data.invoices'), 'invoice_id');

        $this->assertContains(
            $bad->id,
            $reportedIds,
            'The data check must report a cancelled invoice whose journal entry is not reversed'
        );
        $this->assertNotContains(
            $good->id,
            $reportedIds,
            'An invoice cancelled through the fixed path (JE reversed) is not a mismatch'
        );

        $badRow = collect($response->json('data.invoices'))->firstWhere('invoice_id', $bad->id);
        $this->assertSame(
            250.0,
            (float) $badRow['ar_difference'],
            'The reported AR difference is the invoice balance the ledger still carries (10 x 25)'
        );

        $customerRow = collect($response->json('data.by_customer'))
            ->firstWhere('customer_id', $this->customer->id);
        $this->assertNotNull($customerRow, 'The data check must group the AR difference per customer');
        $this->assertSame(250.0, (float) $customerRow['ar_difference']);
        $this->assertSame(1, (int) $customerRow['invoice_count']);
    }
}
