<?php

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\BonusScheme;
use App\Models\BonusSchemeProduct;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceFocLinePairingService;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockLevel;
use Tests\TenantTestCase;

/**
 * #1006: a bonus-scheme FOC line sits directly under the paid line it was
 * earned from — paid first, FOC second — linked to it, kept adjacent when the
 * draft is reordered and removed with its source line.
 */
class InvoiceFocLineOrderingTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(
            fn () => Tenant::factory()->create(['id' => '66666666-6666-6666-6666-666666666666'])
        );
        $this->bindTenantContext($tenant->id);

        $this->user = User::create([
            'name' => 'FOC Admin',
            'email' => 'admin-foc@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create(['track_inventory' => false, 'price' => 100, 'cost' => 40]);
        $this->otherProduct = Product::factory()->create(['track_inventory' => false, 'price' => 50, 'cost' => 20]);
    }

    private function createInvoice(array $lines): Invoice
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-05-06',
            'lines' => $lines,
        ]);

        $response->assertCreated();

        return Invoice::findOrFail($response->json('id'));
    }

    private function linePayload(Product $product, string $description, float $quantity, float $unitPrice, bool $isFoc = false): array
    {
        return [
            'product_id' => $product->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'is_foc' => $isFoc,
        ];
    }

    /** @test */
    public function a_foc_line_submitted_before_its_paid_line_is_reordered_under_it()
    {
        $invoice = $this->createInvoice([
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
            $this->linePayload($this->product, 'Paid units', 5, 100),
            $this->linePayload($this->otherProduct, 'Other product', 2, 50),
        ]);

        /** @var \Illuminate\Support\Collection<int, InvoiceLine> $lines */
        $lines = $invoice->lines()->get();

        // Paid line first, its FOC line directly beneath, other lines keep order.
        $this->assertSame(['Paid units', 'FOC bonus unit', 'Other product'], $lines->pluck('description')->all());
        $this->assertSame([false, true, false], $lines->map(fn (InvoiceLine $line) => (bool) $line->is_foc)->all());
        $this->assertSame([0, 1, 2], $lines->pluck('sequence')->map(fn ($s) => (int) $s)->all());

        $paid = $lines[0];
        $foc = $lines[1];
        $this->assertNull($paid->source_line_id);
        $this->assertSame($paid->id, (int) $foc->source_line_id);

        // Amounts untouched: 5 × 100 + 2 × 50 = 600; the FOC unit is free.
        $this->assertSame(600.0, (float) $invoice->fresh()->total);
        $this->assertSame(0.0, (float) $foc->line_total);
    }

    /** @test */
    public function the_pair_stays_adjacent_when_the_draft_is_reordered()
    {
        $invoice = $this->createInvoice([
            $this->linePayload($this->product, 'Paid units', 5, 100),
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
        ]);

        // Reorder with the FOC line first and another product before the pair.
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'lines' => [
                $this->linePayload($this->otherProduct, 'Other product', 1, 50),
                $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
                $this->linePayload($this->product, 'Paid units', 5, 100),
            ],
        ])->assertOk();

        $lines = $invoice->fresh()->lines()->get();

        $this->assertSame(['Other product', 'Paid units', 'FOC bonus unit'], $lines->pluck('description')->all());
        $this->assertSame($lines[1]->id, (int) $lines[2]->source_line_id);
    }

    /** @test */
    public function removing_the_paid_line_removes_its_orphaned_foc_line()
    {
        $invoice = $this->createInvoice([
            $this->linePayload($this->product, 'Paid units', 5, 100),
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
        ]);

        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->where('is_foc', true)->count());

        // The paid line is gone but the FOC line is still in the payload.
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'lines' => [
                $this->linePayload($this->otherProduct, 'Other product', 1, 50),
                $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
            ],
        ])->assertOk();

        $lines = $invoice->fresh()->lines()->get();
        $this->assertSame(['Other product'], $lines->pluck('description')->all());
        $this->assertSame(0, InvoiceLine::where('invoice_id', $invoice->id)->where('is_foc', true)->count());
    }

    // ── Verifier gaps (#1006 follow-up) ─────────────────────────────────

    /** The payload the invoice screen now sends: FOC flag + source index. */
    private function uiLine(Product $product, string $description, float $quantity, float $unitPrice, ?int $sourceLineIndex = null): array
    {
        return array_merge($this->linePayload($product, $description, $quantity, $unitPrice, $sourceLineIndex !== null), array_filter([
            'source_line_index' => $sourceLineIndex,
        ], fn ($v) => $v !== null));
    }

    /** @test */
    public function the_ui_payload_keeps_the_foc_flag_and_its_explicit_source_across_draft_edits()
    {
        // Two paid lines of the same product: the FOC line names the SECOND
        // one, which the nearest-same-product guess would not pick.
        $invoice = $this->createInvoice([
            $this->uiLine($this->product, 'Paid batch A', 5, 100),
            $this->uiLine($this->product, 'FOC for batch B', 1, 0, 2),
            $this->uiLine($this->product, 'Paid batch B', 10, 100),
        ]);

        $lines = $invoice->lines()->get();
        $this->assertSame(['Paid batch A', 'Paid batch B', 'FOC for batch B'], $lines->pluck('description')->all());
        $this->assertTrue((bool) $lines[2]->is_foc);
        $this->assertSame($lines[1]->id, (int) $lines[2]->source_line_id);

        // A UI save re-sends every line (delete + recreate on the server).
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'lines' => [
                $this->uiLine($this->otherProduct, 'Other product', 1, 50),
                $this->uiLine($this->product, 'Paid batch A', 5, 100),
                $this->uiLine($this->product, 'Paid batch B', 10, 100),
                $this->uiLine($this->product, 'FOC for batch B', 1, 0, 2),
            ],
        ])->assertOk();

        $lines = $invoice->fresh()->lines()->get();
        $this->assertSame(['Other product', 'Paid batch A', 'Paid batch B', 'FOC for batch B'], $lines->pluck('description')->all());
        $this->assertSame([false, false, false, true], $lines->map(fn (InvoiceLine $line) => (bool) $line->is_foc)->all());
        $this->assertSame($lines[2]->id, (int) $lines[3]->source_line_id);
        $this->assertSame(0.0, (float) $lines[3]->unit_price);
        // The free unit is traceable: carried at 0 with an audit reason.
        $this->assertSame('Bonus scheme FOC', $lines[3]->pricing_trace['override_reason'] ?? null);

        // A header-only edit leaves the pair untouched.
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'notes' => 'header only',
        ])->assertOk();
        $lines = $invoice->fresh()->lines()->get();
        $this->assertSame($lines[2]->id, (int) $lines[3]->source_line_id);

        // The UI removes the paid line together with its FOC child.
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'lines' => [
                $this->uiLine($this->otherProduct, 'Other product', 1, 50),
                $this->uiLine($this->product, 'Paid batch A', 5, 100),
            ],
        ])->assertOk();
        $lines = $invoice->fresh()->lines()->get();
        $this->assertSame(['Other product', 'Paid batch A'], $lines->pluck('description')->all());
        $this->assertSame(0, InvoiceLine::where('invoice_id', $invoice->id)->where('is_foc', true)->count());
    }

    /** @test */
    public function a_scheme_mapped_foc_product_pairs_under_its_qualifying_paid_line()
    {
        $scheme = BonusScheme::create([
            'scheme_code' => 'BNS-1006-'.uniqid(),
            'name' => 'Buy 5 get 1 other',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $this->product->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 5,
            'foc_product_id' => $this->otherProduct->id,
            'foc_qty' => 1,
            'status' => 'active',
        ]);

        $invoice = $this->createInvoice([
            $this->linePayload($this->otherProduct, 'FOC other product', 1, 0, true),
            $this->linePayload($this->otherProduct, 'Other product paid', 2, 50),
            $this->linePayload($this->product, 'Paid units', 5, 100),
        ]);

        $lines = $invoice->lines()->get();
        // Same-product paid line still wins over the scheme mapping…
        $this->assertSame(['Other product paid', 'FOC other product', 'Paid units'], $lines->pluck('description')->all());

        // …and with no same-product paid line the scheme mapping pairs it.
        $mapped = $this->createInvoice([
            $this->linePayload($this->otherProduct, 'FOC other product', 1, 0, true),
            $this->linePayload($this->product, 'Paid units', 5, 100),
        ]);

        $lines = $mapped->lines()->get();
        $this->assertCount(2, $lines, 'The FOC line must not be dropped');
        $this->assertSame(['Paid units', 'FOC other product'], $lines->pluck('description')->all());
        $this->assertTrue((bool) $lines[1]->is_foc);
        $this->assertSame($lines[0]->id, (int) $lines[1]->source_line_id);
        $this->assertSame(500.0, (float) $mapped->fresh()->total);
    }

    /** @test */
    public function an_foc_line_with_no_paid_source_is_rejected_not_dropped()
    {
        $before = Invoice::count();

        $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-05-06',
            'lines' => [
                $this->linePayload($this->otherProduct, 'Other product', 1, 50),
                $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.1.is_foc']);

        $this->assertSame($before, Invoice::count(), 'No invoice may be created');

        // An explicit source that is not a paid product line is rejected too.
        $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-05-06',
            'lines' => [
                $this->uiLine($this->product, 'Paid units', 5, 100),
                $this->uiLine($this->product, 'FOC bonus unit', 1, 0, 1),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.1.source_line_index']);

        $this->assertSame($before, Invoice::count());
    }

    /** @test */
    public function the_backfill_never_recomputes_a_posted_invoices_amounts()
    {
        $invoice = $this->createInvoice([
            array_merge($this->linePayload($this->product, 'Paid units', 5, 100), ['tax_rate' => 10]),
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
        ]);
        $this->assertSame(0.0, (float) $invoice->lines()->get()[1]->unit_price, 'Fixture: the FOC unit is free');

        $paid = $invoice->lines()->get()[0];
        $foc = $invoice->lines()->get()[1];

        // A posted invoice whose FOC line was stored before its paid line and
        // never linked (the legacy shape the backfill exists for). The paid
        // line's tax rate changed after posting — recomputing it would move
        // the posted tax and total.
        DB::table('invoice_lines')->where('id', $foc->id)->update(['sequence' => 0, 'is_foc' => false, 'source_line_id' => null]);
        DB::table('invoice_lines')->where('id', $paid->id)->update(['sequence' => 1, 'tax_rate' => 20]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'posted']);

        $before = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')
            ->get(['id', 'line_total', 'tax_amount', 'unit_price'])->map(fn ($r) => (array) $r)->all();
        $headerBefore = (array) DB::table('invoices')->where('id', $invoice->id)->first(['subtotal', 'tax_amount', 'total']);

        $paired = app(InvoiceFocLinePairingService::class)->normalize(Invoice::findOrFail($invoice->id));
        $this->assertSame(1, $paired);

        $lines = Invoice::findOrFail($invoice->id)->lines()->get();
        $this->assertSame(['Paid units', 'FOC bonus unit'], $lines->pluck('description')->all());
        $this->assertSame($paid->id, (int) $lines[1]->source_line_id);

        $after = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')
            ->get(['id', 'line_total', 'tax_amount', 'unit_price'])->map(fn ($r) => (array) $r)->all();
        $headerAfter = (array) DB::table('invoices')->where('id', $invoice->id)->first(['subtotal', 'tax_amount', 'total']);

        $this->assertEquals($before, $after, 'Posted line amounts must never change');
        $this->assertEquals($headerBefore, $headerAfter, 'Posted header totals must never change');
    }

    /** @test */
    public function posting_moves_stock_and_cogs_for_the_foc_quantity_and_the_entry_balances()
    {
        FiscalPeriod::firstOrCreate(['code' => now()->format('Y-m')], [
            'name' => now()->format('F Y'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
        $warehouse = Warehouse::create(['name' => 'FOC WH', 'code' => 'FOC-'.uniqid(), 'is_active' => true, 'is_default' => true]);
        $stocked = Product::factory()->create(['track_inventory' => true, 'price' => 100, 'cost' => 40, 'stock_quantity' => 0]);
        StockLevel::create(['product_id' => $stocked->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20, 'reserved_quantity' => 0, 'min_quantity' => 0]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [
                $this->uiLine($stocked, 'Paid units', 5, 100),
                $this->uiLine($stocked, 'FOC bonus unit', 1, 0, 0),
            ],
        ])->assertCreated();
        $invoiceId = (int) $response->json('id');

        $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoiceId}/post")->assertOk();
        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame('posted', $invoice->status);
        $this->assertSame(500.0, (float) $invoice->total);

        // 5 paid + 1 free unit left the warehouse.
        $this->assertSame(14.0, (float) StockLevel::where('product_id', $stocked->id)->where('warehouse_id', $warehouse->id)->value('quantity'));

        $entryIds = JournalEntryLink::where('source_document_type', 'invoice')
            ->where('source_document_id', $invoiceId)
            ->where('relation_type', 'post')
            ->pluck('journal_entry_id');
        $this->assertNotEmpty($entryIds);

        // COGS carries the FOC unit too: 6 × 40.
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');
        $this->assertSame(240.0, round((float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)->where('account_id', $cogsAccountId)->sum('debit'), 2));

        // The books balance.
        $debits = round((float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)->sum('debit'), 2);
        $credits = round((float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)->sum('credit'), 2);
        $this->assertSame($debits, $credits);
    }
}
