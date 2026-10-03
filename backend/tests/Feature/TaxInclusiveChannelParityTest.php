<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantRoleService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #303 (X3): tax-inclusive pricing must reach every sales document.
 *
 * The inclusive flag is resolved per line (engine / product override) when the
 * client does not send it, persisted, and carried through
 * quote -> Sales Order -> Invoice and POS -> Sales Order. The guard: an
 * inclusive-priced product gives the same gross total on POS and on an invoice.
 */
class TaxInclusiveChannelParityTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private PosTerminal $terminal;

    private PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxincl.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // POS routes are entitlement-gated; the tenant is entitled in tests.
        $gateMock = \Mockery::mock(FeatureGateService::class);
        $gateMock->shouldReceive('check')->andReturn([
            'key' => 'pos', 'is_entitled' => true, 'source' => 'tier_included', 'name' => 'POS',
        ]);
        $gateMock->shouldReceive('all')->andReturn([['key' => 'pos', 'source' => 'tier_included', 'name' => 'POS']]);
        $gateMock->shouldReceive('invalidate')->andReturnNull();
        app()->instance(FeatureGateService::class, $gateMock);

        $roleMock = \Mockery::mock(TenantRoleService::class);
        $roleMock->shouldReceive('hasPermission')->andReturn(true);
        $roleMock->shouldReceive('hasAnyPermission')->andReturn(true);
        app()->instance(TenantRoleService::class, $roleMock);

        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create();
        $this->terminal = PosTerminal::factory()->create(['warehouse_id' => $this->warehouse->id]);
        $this->session = PosSession::factory()->create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);
    }

    /**
     * A 10% rule flagged exclusive, overridden to inclusive: the engine must
     * resolve rate = 10, is_inclusive = true for this product.
     */
    private function inclusiveProduct(): Product
    {
        $rule = Tax::create([
            'name' => 'Inclusive 10 '.Str::random(4), 'code' => 'INC-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 10,
            'is_active' => true, 'is_inclusive' => false,
        ]);

        $product = Product::create([
            'name' => 'Inclusive priced item', 'sku' => 'INC-'.Str::upper(Str::random(6)),
            'is_active' => true, 'track_inventory' => false, 'tax_mode' => 'default',
            'tax_inclusive_override' => true, 'tax_rate' => 10, 'price' => 110,
        ]);

        $product->forceFill(['tax_id' => $rule->id])->save();

        return $product->refresh();
    }

    public function test_inclusive_priced_product_gives_same_gross_total_on_pos_and_invoice(): void
    {
        $product = $this->inclusiveProduct();

        // ---- POS: the price already contains the tax (real controller path) ----
        $posResponse = $this->actingAs($this->user)->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 110.00],
            ],
        ]);
        $posResponse->assertStatus(201);

        $posOrder = PosOrder::with('items')->find($posResponse->json('id'));
        $posGross = round((float) $posOrder->items->sum('line_total'), 2);
        $this->assertSame(110.00, $posGross, 'POS must extract the 10% tax out of the inclusive price.');

        // ---- POS -> Sales Order mirroring carries the flag (#303) ----
        $mirrored = app(SalesOrderService::class)->createFromPosOrder($posOrder);
        $mirrored->loadMissing('lines');
        $this->assertTrue((bool) $mirrored->lines->first()->tax_inclusive, 'POS -> SO must carry tax_inclusive.');
        $this->assertSame(110.00, round((float) $mirrored->total, 2), 'Mirrored order keeps the inclusive gross.');

        // ---- Invoice: same product, same price - formerly tax added on top ----
        $invoiceResponse = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Inclusive item',
                'quantity' => 1,
                'unit_price' => 110.00,
                'tax_rate' => 10.00,
                'discount_amount' => 0,
            ]],
        ]);
        $invoiceResponse->assertCreated();

        $invoice = Invoice::with('lines')->find($invoiceResponse->json('id'));
        $this->assertTrue((bool) $invoice->lines->first()->tax_inclusive, 'The invoice line must persist tax_inclusive.');
        $this->assertSame(
            $posGross,
            round((float) $invoice->total, 2),
            'Same product and price must give the same gross total on POS and on an invoice.'
        );
    }

    public function test_tax_inclusive_flag_is_carried_from_quotation_to_sales_order_to_invoice(): void
    {
        $product = $this->inclusiveProduct();

        $quotationResponse = $this->actingAs($this->user)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Inclusive item',
                'quantity' => 1,
                'unit_price' => 110.00,
                'tax_rate' => 10.00,
                'discount_amount' => 0,
            ]],
        ]);
        $quotationResponse->assertCreated();

        $quotation = Quotation::with('lines')->find($quotationResponse->json('data.id'));
        $this->assertTrue((bool) $quotation->lines->first()->tax_inclusive, 'The quotation line must persist tax_inclusive.');
        $this->assertSame(110.00, round((float) $quotation->total, 2), 'Quotation total must extract the inclusive tax.');

        $confirmResponse = $this->actingAs($this->user)->postJson("/api/v1/quotations/{$quotation->id}/confirm");
        $confirmResponse->assertOk();

        $quotation->refresh();
        $salesOrder = SalesOrder::with('lines')->find($quotation->sales_order_id);
        $this->assertNotNull($salesOrder, 'Confirm must release a Sales Order.');
        $this->assertTrue((bool) $salesOrder->lines->first()->tax_inclusive, 'Quote -> SO must carry tax_inclusive.');
        $this->assertSame(110.00, round((float) $salesOrder->total, 2), 'SO total stays inclusive.');

        $invoice = app(SalesOrderService::class)->createInvoice($salesOrder);
        $invoice->loadMissing('lines');
        $this->assertTrue((bool) $invoice->lines->first()->tax_inclusive, 'SO -> Invoice must carry tax_inclusive.');
        $this->assertSame(110.00, round((float) $invoice->total, 2), 'Invoice total stays inclusive.');
    }

    public function test_inclusive_invoice_posts_to_a_balanced_journal(): void
    {
        $product = $this->inclusiveProduct();

        $invoiceResponse = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Inclusive item',
                'quantity' => 1,
                'unit_price' => 110.00,
                'tax_rate' => 10.00,
                'discount_amount' => 0,
            ]],
        ]);
        $invoiceResponse->assertCreated();
        $invoiceId = $invoiceResponse->json('id');

        $sendResponse = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoiceId}/send");
        $sendResponse->assertOk();

        $invoice = Invoice::with(['journalEntry.lines', 'lines'])->find($invoiceId);
        $this->assertSame(110.00, round((float) $invoice->total, 2));
        $this->assertNotNull($invoice->journalEntry, 'Posting must create a journal entry.');

        $debits = round((float) $invoice->journalEntry->lines->sum('debit'), 2);
        $credits = round((float) $invoice->journalEntry->lines->sum('credit'), 2);
        $this->assertSame($debits, $credits, "Inclusive invoice journal must balance: debits={$debits} credits={$credits}");
    }

    public function test_an_explicit_exclusive_flag_wins_over_the_product_override(): void
    {
        $product = $this->inclusiveProduct();

        $invoiceResponse = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Explicitly exclusive line',
                'quantity' => 1,
                'unit_price' => 110.00,
                'tax_rate' => 10.00,
                'tax_inclusive' => false,
                'discount_amount' => 0,
            ]],
        ]);
        $invoiceResponse->assertCreated();

        $invoice = Invoice::with('lines')->find($invoiceResponse->json('id'));
        $this->assertFalse((bool) $invoice->lines->first()->tax_inclusive);
        $this->assertSame(121.00, round((float) $invoice->total, 2), 'Explicit exclusive means tax is added on top.');
    }
}
