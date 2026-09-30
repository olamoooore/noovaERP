<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\PriceListApplication;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency-mrd — end-to-end document suite through the
 * REAL router (SOP §3: no source-string tests, no direct middleware
 * instantiation).
 *
 * Tenant base = SGD. Sellable: SGD, USD, MYR (EUR deliberately disabled to
 * prove FR-1.1). Live rate pair: USD→SGD = 1.3500.
 */
class DocumentMultiCurrencyTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Harness mirrors the proven SalesOrderPermissionEnforcementTest:
        // bindTenantContextWithPermissions + Tenant::create + the seeded
        // permission matrix, so non-admin users exercise RequirePermission
        // exactly as they will in production.
        $this->tenantId = (string) \Illuminate\Support\Str::uuid();
        $this->bindTenantContextWithPermissions($this->tenantId, null, UserRole::ADMIN->spatieName(), autoAssignRole: false);
        \App\Models\Tenant::withoutEvents(fn () => \App\Models\Tenant::create([
            'id' => $this->tenantId,
            'name' => 'MultiCcy Doc Tenant',
            'email' => 'mc-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'plan' => 'standard',
        ]));
        $this->admin = $this->makeRoleUser(UserRole::ADMIN);
        CompanySettings::query()->create(['base_currency' => 'SGD']);

        DB::table('currencies')->where('code', 'EUR')->update(['is_sellable' => false]);

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3500,
            'effective_date' => now()->subDays(2)->toDateString(), 'source' => 'api',
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Acme Pte Ltd', 'payment_terms' => 30]);
    }

    private function product(float $price = 100.0, array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'price' => $price,
            'cost' => 40.00,
            'track_inventory' => false,
        ], $attrs));
    }

    private function usdList(Product $product, float $percentOff = 10.0): PriceList
    {
        $list = PriceList::create([
            'name' => 'Export USD',
            'code' => 'EXPORT-USD',
            'currency_code' => 'USD',
            'is_active' => true,
            'sequence' => 10,
        ]);
        PriceListApplication::create([
            'price_list_id' => $list->id,
            'applies_to_type' => 'customer',
            'applies_to_id' => $this->customer->id,
        ]);
        PriceListItem::create([
            'price_list_id' => $list->id,
            'sequence' => 10,
            'apply_on' => 'all',
            'compute_type' => 'percentage',
            'percent' => $percentOff,
        ]);

        return $list;
    }

    /**
     * Create a tenant-schema user, mirror into public.users (central), grant
     * the Spatie role and bind the central role via user_company_access —
     * the exact chain SetTenantFromUserPreference + RequirePermission follow.
     */
    private function makeRoleUser(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => (int) $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return $user;
    }

    private function actingAsRole(UserRole $role): User
    {
        return $this->makeRoleUser($role);
    }

    // ════════════════════════════════════════════════════════════════════
    // FR-2/FR-10/FR-11 — price list CRUD + permission enforcement (AC-7)
    // ════════════════════════════════════════════════════════════════════

    public function test_price_list_crud_and_clone_through_the_router(): void
    {
        $product = $this->product(100.0);

        $create = $this->actingAs($this->admin)->postJson('/api/v1/price-lists', [
            'name' => 'Export USD',
            'code' => 'EXPORT-USD',
            'currency_code' => 'USD',
            'rounding_enabled' => true,
            'rounding_method' => 'nearest',
            'rounding_precision' => 0.05,
            'applications' => [['applies_to_type' => 'customer', 'applies_to_id' => $this->customer->id]],
            'items' => [
                ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'percentage', 'percent' => 10],
            ],
        ]);
        $create->assertCreated()->assertJsonPath('data.currency_code', 'USD');
        $listId = (int) $create->json('data.id');

        $update = $this->actingAs($this->admin)->putJson("/api/v1/price-lists/{$listId}", [
            'items' => [
                ['sequence' => 10, 'apply_on' => 'product', 'product_id' => $product->id, 'compute_type' => 'fixed', 'fixed_price' => 88.8],
            ],
        ]);
        $update->assertOk()->assertJsonPath('data.items.0.fixed_price', 88.8);

        $clone = $this->actingAs($this->admin)->postJson("/api/v1/price-lists/{$listId}/clone");
        $clone->assertStatus(201);
        $this->assertSame(1, $clone->json('data.items_count') ?? count($clone->json('data.items', [])));
        $this->assertFalse((bool) $clone->json('data.is_active'), 'clones start inactive (FR-2.6)');

        $deactivate = $this->actingAs($this->admin)->postJson("/api/v1/price-lists/{$listId}/deactivate");
        $deactivate->assertOk();
        $this->assertFalse((bool) PriceList::find($listId)->is_active);
    }

    public function test_ac7_sales_cannot_manage_pricelists(): void
    {
        $sales = $this->actingAsRole(UserRole::SALES);

        // Manage API → 403 through the REAL router with the exact permission.
        $this->actingAs($sales)->postJson('/api/v1/price-lists', [
            'name' => 'Nope', 'currency_code' => 'USD',
        ])->assertForbidden()->assertJsonPath('required_permission', 'pricelists.manage');

        $this->actingAs($sales)->getJson('/api/v1/price-lists')->assertOk(); // view is granted
    }

    public function test_cost_rules_visible_to_manager_masked_for_sales(): void
    {
        // §10 + FR-3.6: Manager holds pricelists.manage AND pricelists.cost,
        // so it can create cost-based rules…
        $manager = $this->makeRoleUser(UserRole::MANAGER);
        $created = $this->actingAs($manager)->postJson('/api/v1/price-lists', [
            'name' => 'Cost plus 20',
            'code' => 'COSTPLUS',
            'currency_code' => 'SGD',
            'applications' => [['applies_to_type' => 'all']],
            'items' => [
                ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'formula', 'base' => 'cost', 'markup_percent' => 20],
            ],
        ]);
        $created->assertCreated();
        $listId = (int) $created->json('data.id');
        $this->assertSame('cost', $created->json('data.items.0.base'), 'manager sees the cost base');

        // …while a Sales user — same list, same rule — sees ONLY the computed
        // price (trace masked, base not exposed) through the resolve API.
        $product = $this->product(100.0); // cost 40 → 48.00 at +20%
        $sales = $this->makeRoleUser(UserRole::SALES);
        $resolve = $this->actingAs($sales)->postJson('/api/v1/pricing/resolve', [
            'product_id' => $product->id,
            'quantity' => 1,
            'price_list_id' => $listId,
        ]);
        $resolve->assertOk();
        $this->assertSame(48.00, round((float) $resolve->json('data.unit_price'), 2));
        $this->assertSame('cost', $resolve->json('data.pricing_trace.base'), 'rule kind is disclosed');
        $this->assertNull($resolve->json('data.pricing_trace.base_value'), 'cost INPUT is masked for non-cost roles (FR-3.6)');

        // And the list payload hides cost-rule internals from Sales.
        $shown = $this->actingAs($sales)->getJson("/api/v1/price-lists/{$listId}");
        $shown->assertOk();
        $this->assertSame('restricted', $shown->json('data.items.0.compute_type'), 'cost rule internals masked');
        $adminView = $this->actingAs($this->makeRoleUser(UserRole::ACCOUNTANT))->getJson("/api/v1/price-lists/{$listId}");
        $this->assertSame('formula', $adminView->json('data.items.0.compute_type'));
    }

    public function test_unsellable_currency_rejected_on_pricelist_and_document(): void
    {
        // FR-1.1 — EUR is active but not sellable.
        $this->actingAs($this->admin)->postJson('/api/v1/price-lists', [
            'name' => 'EUR leak', 'currency_code' => 'EUR',
        ])->assertStatus(422);

        $product = $this->product();
        $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'EUR',
            'lines' => [[
                'product_id' => $product->id, 'description' => 'Widget', 'quantity' => 1, 'price_mode' => 'auto',
            ]],
        ])->assertStatus(422);
    }

    // ════════════════════════════════════════════════════════════════════
    // FR-4/FR-5 — quotation currency default, line pricing, dual amounts
    // ════════════════════════════════════════════════════════════════════

    public function test_quotation_defaults_to_customer_pricelist_currency_and_prices_lines(): void
    {
        $product = $this->product(100.0);
        $list = $this->usdList($product, percentOff: 10.0);
        $this->customer->update(['price_list_id' => $list->id]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 5, 'price_mode' => 'auto', 'tax_rate' => 0],
            ],
        ]);
        $response->assertCreated();

        $quotation = Quotation::find($response->json('data.id'));
        $this->assertNotNull($quotation);
        $this->assertSame('USD', $quotation->currency_code, 'FR-5.2: default = customer pricelist currency');
        $this->assertSame(1.350000, round((float) $quotation->exchange_rate, 6), 'USD→SGD snapshot stamped');
        $this->assertSame('SGD', $quotation->base_currency_code);

        $line = $quotation->lines()->first();
        // Odoo-exact semantics: the −10% applies to the catalogue price
        // EXPRESSED IN THE LIST CURRENCY (100 SGD ÷ 1.35 = 74.07 USD → 66.67),
        // which round-trips to exactly 90.00 SGD — the customer gets the
        // 10% discount it advertises, never a hidden FX markup.
        $this->assertSame(66.67, round((float) $line->unit_price, 2), 'USD list unit price');
        $this->assertSame(90.00, round((float) $line->base_unit_price, 2), 'exactly −10% of the 100 SGD catalogue');
        $this->assertSame('pricelist', $line->pricing_trace['source']);
        $this->assertSame($list->id, $line->pricing_trace['pricelist_id']);
        $this->assertSame('EXPORT-USD', $line->pricing_trace['pricelist_code']);

        // Dual totals (FR-5.4): document-currency primary on the totals row
        $this->assertSame(333.35, round((float) $quotation->total, 2), '5 × 66.67 USD');
    }

    public function test_explicit_document_currency_overrides_pricelist_default(): void
    {
        $product = $this->product(100.0);
        $list = $this->usdList($product);
        $this->customer->update(['price_list_id' => $list->id]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'SGD', // client insists on SGD
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 2, 'price_mode' => 'auto', 'tax_rate' => 0],
            ],
        ]);
        $response->assertCreated();

        $quotation = Quotation::find($response->json('data.id'));
        $this->assertSame('SGD', $quotation->currency_code);
        $this->assertSame(1.000000, round((float) $quotation->exchange_rate, 6));

        $line = $quotation->lines()->first();
        // FR-4.3 bridge: −10% of 100 SGD expressed into SGD = 90.00
        $this->assertSame(90.00, round((float) $line->unit_price, 2));
        $this->assertSame(90.00, round((float) $line->base_unit_price, 2));
    }

    public function test_manual_override_requires_reason_below_floor_through_api(): void
    {
        $product = $this->product(100.0);

        $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'lines' => [
                [
                    'product_id' => $product->id, 'description' => 'Widget', 'quantity' => 1,
                    'unit_price' => 50.00, 'price_mode' => 'manual',
                ],
            ],
        ])->assertStatus(422);

        $ok = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'lines' => [
                [
                    'product_id' => $product->id, 'description' => 'Widget', 'quantity' => 1,
                    'unit_price' => 50.00, 'price_mode' => 'manual',
                    'override_reason' => 'tender discount approved by management',
                ],
            ],
        ]);
        $ok->assertCreated();
        $line = Quotation::find($ok->json('data.id'))->lines()->first();
        $this->assertSame('manual', $line->pricing_trace['source']);
        $this->assertSame('tender discount approved by management', $line->pricing_trace['override_reason']);
        $this->assertTrue((bool) $line->pricing_trace['override_below_floor']);
    }

    // ════════════════════════════════════════════════════════════════════
    // AC-5 — conversion chain preserves currency, rate and prices
    // ════════════════════════════════════════════════════════════════════

    public function test_ac5_quotation_to_so_to_invoice_chain_preserves_currency(): void
    {
        $product = $this->product(100.0);
        $list = $this->usdList($product);
        $this->customer->update(['price_list_id' => $list->id]);

        // 1) QUOTATION (USD by default)
        $quoteResponse = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 4, 'price_mode' => 'auto', 'tax_rate' => 0],
            ],
        ]);
        $quoteResponse->assertCreated();
        $quotationId = (int) $quoteResponse->json('data.id');
        $quotation = Quotation::find($quotationId);
        $this->assertSame('USD', $quotation->currency_code);
        $rate = round((float) $quotation->exchange_rate, 6);
        $this->assertSame(1.350000, $rate);

        // 2) CONFIRM → SalesOrder (conversion must NOT re-price, FR-5.5)
        $confirm = $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotationId}/confirm");
        $confirm->assertOk();

        $salesOrder = $quotation->fresh()->salesOrder;
        $this->assertNotNull($salesOrder, 'confirm created the sales order');
        $this->assertSame('USD', $salesOrder->currency_code, 'SO inherits quotation currency');
        $this->assertSame($rate, round((float) $salesOrder->exchange_rate, 6), 'rate snapshot propagates unchanged');
        $soLine = $salesOrder->lines()->first();
        $this->assertSame(66.67, round((float) $soLine->unit_price, 2), 'no re-pricing: same 66.67 USD');
        $this->assertSame(90.00, round((float) $soLine->base_unit_price, 2));
        $this->assertSame('pricelist', $soLine->pricing_trace['source']);

        // 3) SO → INVOICE (same snapshot + trace, FR-5.5)
        $invoice = app(\Modules\Sales\Services\SalesOrderService::class)->createInvoice($salesOrder->fresh());
        $this->assertSame('USD', $invoice->currency_code);
        $this->assertSame($rate, round((float) $invoice->exchange_rate, 6));
        $invLine = $invoice->lines()->first();
        $this->assertSame(66.67, round((float) $invLine->unit_price, 2));
        $this->assertSame(90.00, round((float) $invLine->base_unit_price, 2));
        $this->assertSame($soLine->pricing_trace, $invLine->pricing_trace, 'trace is byte-identical along the chain');

        // AC-5: identical base equivalents along the chain — same lines,
        // same snapshot rate, no re-pricing anywhere.
        $this->assertSame(266.68, round((float) $invoice->total, 2), '4 × 66.67 USD');
        $this->assertSame(
            round((float) $salesOrder->total * $rate, 2),
            round((float) $invoice->total * $rate, 2),
            'base equivalent is identical through the whole chain'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // AC-2 — invoice in foreign currency posts base-currency GL at snapshot
    // ════════════════════════════════════════════════════════════════════

    public function test_ac2_foreign_invoice_posts_base_amounts_at_snapshot_rate(): void
    {
        $product = $this->product(100.0);

        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $invoiceResponse->assertCreated();
        $invoiceId = (int) $invoiceResponse->json('id');

        $invoice = Invoice::find($invoiceId);
        $this->assertSame('USD', $invoice->currency_code);
        $this->assertSame(1.350000, round((float) $invoice->exchange_rate, 6));
        $this->assertSame('SGD', $invoice->base_currency_code);

        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post");
        $post->assertOk();

        $invoice->refresh();
        $this->assertSame('posted', $invoice->status);
        $this->assertNotNull($invoice->journal_entry_id);

        $entry = \App\Models\JournalEntry::with('lines')->find($invoice->journal_entry_id);

        // Header FX stamp (FR-5.7)
        $this->assertSame('USD', $entry->original_currency_code);
        $this->assertSame(1000.00, (float) $entry->original_total_amount);
        $this->assertSame(1.350000, round((float) $entry->exchange_rate, 6));

        // AR line: 1,000 USD @ 1.35 → DR 1,350.00 SGD with original columns
        $ar = $entry->lines->firstWhere('description', 'like', '%Invoice%');
        $arLine = $entry->lines->first(fn ($l) => (float) $l->debit === 1350.00);
        $this->assertNotNull($arLine, 'AR debit of 1,350.00 SGD must exist');
        $this->assertSame(1000.00, (float) $arLine->original_amount, 'original amount = 1,000 USD');
        $this->assertSame(1.350000, round((float) $arLine->exchange_rate, 6));

        // Entry balances (base)
        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'journal must balance in base currency'
        );

        // original_amount is the DOCUMENT-currency figure as stored (the
        // legacy model accessor synthesizes debit/credit when NULL, so the
        // assertion reads the raw column).
        $rawArOriginal = DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)
            ->whereNotNull('original_amount')->max('original_amount');
        $this->assertSame(1000.00, round((float) $rawArOriginal, 2), 'raw original = 1,000 USD');
    }

    // ════════════════════════════════════════════════════════════════════
    // AC-3 — no rate, no post
    // ════════════════════════════════════════════════════════════════════

    public function test_ac3_missing_rate_blocks_posting_without_touching_ledger(): void
    {
        $product = $this->product(100.0);

        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'MYR', // no MYR rates exist at all
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);

        // Creation is refused for MYR: the document line cannot be priced
        // without a rate (PricingService is the only price authority).
        $invoiceResponse->assertStatus(422);
        $this->assertSame(0, DB::table('journal_entries')->count(), 'no journal rows written (AC-3)');

        // A rate recorded afterwards makes the same flow succeed (custom rate
        // setup unblocks documents — FR-1.4/AC-3).
        ExchangeRate::create([
            'from_currency' => 'MYR', 'to_currency' => 'SGD', 'rate' => 0.3012,
            'effective_date' => now()->toDateString(), 'source' => 'manual',
        ]);

        $retry = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'MYR',
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $retry->assertCreated();
        $invoice = Invoice::find((int) $retry->json('id'));
        $this->assertSame('MYR', $invoice->currency_code);
        $this->assertSame(0.301200, round((float) $invoice->exchange_rate, 6));
        $this->assertSame(30.12, round((float) $invoice->lines()->first()->base_unit_price, 2));
    }

    // ════════════════════════════════════════════════════════════════════
    // AC-4 — realized FX at payment
    // ════════════════════════════════════════════════════════════════════

    public function test_ac4_realized_fx_gain_posts_on_settlement(): void
    {
        $product = $this->product(100.0);

        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $invoiceId = (int) $invoiceResponse->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post")->assertOk();

        // Payment settles at a DIFFERENT rate: 1 USD = 1.3620 SGD on the day.
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3620,
            'effective_date' => now()->toDateString(), 'source' => 'manual',
        ]);

        $payment = $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 1000.00,
            'method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
        ]);
        $payment->assertCreated();
        $paymentId = (int) $payment->json('id');

        $post = $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/post");
        $post->assertOk();

        $posted = \App\Models\Payment::with('journalEntry.lines')->find($paymentId);
        $this->assertNotNull($posted->journal_entry_id, 'payment posted');

        $entry = $posted->journalEntry;
        $byAccount = fn (string $codeFragment) => $entry->lines
            ->map(fn ($l) => ['code' => $l->account?->code, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit])
            ->filter(fn ($r) => $r['code'] !== null && str_contains($r['code'], $codeFragment));

        // DR bank 1,362.00 — settlement rate
        $this->assertSame(1362.00, $byAccount('1020')->sum('debit') + $byAccount('1010')->sum('debit'), 'bank debited at 1.3620');
        // CR AR 1,350.00 — invoice snapshot rate (AR clears at its own rate)
        $this->assertSame(1350.00, $byAccount('1100')->sum('credit'));

        // FX gain 12.00 posts to the realized gain account (72xx family)
        $fxLines = $entry->lines->filter(fn ($l) => optional($l->account)->code === '7201');
        $this->assertSame(12.00, round((float) $fxLines->sum('credit'), 2), 'FX gain 1,362 − 1,350 = 12.00 credited to 7201');

        // Balanced entry (FR-7.4: never unbalanced)
        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2)
        );
    }

    public function test_realized_fx_loss_direction_at_weaker_settlement(): void
    {
        $product = $this->product(100.0);

        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $invoiceId = (int) $invoiceResponse->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post")->assertOk();

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3380,
            'effective_date' => now()->toDateString(), 'source' => 'manual',
        ]);

        $payment = $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoiceId, 'amount' => 1000.00, 'method' => 'bank_transfer',
        ]);
        $paymentId = (int) $payment->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/post")->assertOk();

        $entry = \App\Models\Payment::with('journalEntry.lines')->find($paymentId)->journalEntry;

        $loss = $entry->lines->filter(fn ($l) => optional($l->account)->code === '7202');
        $this->assertSame(12.00, round((float) $loss->sum('debit'), 2), 'weaker settlement → 12.00 realized FX LOSS debited to 7202');

        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2)
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // FR-5.9 — credit note inherits invoice currency + snapshot
    // ════════════════════════════════════════════════════════════════════

    public function test_credit_note_inherits_invoice_currency_and_rate(): void
    {
        $product = $this->product(100.0);

        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $invoiceId = (int) $invoiceResponse->json('id');

        $cn = $this->actingAs($this->admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoiceId,
            'reason' => 'Damaged goods',
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $cn->assertCreated();

        $note = \App\Models\CustomerCreditNote::find((int) $cn->json('data.id') ?? (int) $cn->json('id'));
        $this->assertNotNull($note);
        $this->assertSame('USD', $note->currency_code, 'FR-5.9: inherit invoice currency');
        $this->assertSame(1.350000, round((float) $note->exchange_rate, 6), 'and the rate snapshot');
        $this->assertSame('SGD', $note->base_currency_code);
    }

    // ════════════════════════════════════════════════════════════════════
    // FR-5.2 — guarded currency change re-prices auto lines only
    // ════════════════════════════════════════════════════════════════════

    public function test_document_currency_change_reprices_auto_lines_keeps_manual(): void
    {
        $product = $this->product(100.0);
        $list = $this->usdList($product, percentOff: 10.0);
        $this->customer->update(['price_list_id' => $list->id]);

        $q = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Auto', 'quantity' => 1, 'price_mode' => 'auto'],
                ['product_id' => $product->id, 'description' => 'Hand', 'quantity' => 1, 'unit_price' => 95.0, 'price_mode' => 'manual', 'override_reason' => 'goodwill'],
            ],
        ]);
        $q->assertCreated();
        $quotationId = (int) $q->json('data.id');

        $change = $this->actingAs($this->admin)->postJson("/api/v1/documents/quotation/{$quotationId}/currency", [
            'currency_code' => 'SGD',
        ]);
        $change->assertOk();
        $this->assertSame(1, (int) $change->json('meta.repriced_lines'), 'only the auto line re-prices');
        $this->assertSame(1, (int) $change->json('meta.kept_manual_lines'), 'the manual line keeps its price (FR-5.2)');

        $quotation = Quotation::with('lines')->find($quotationId);
        $this->assertSame('SGD', $quotation->currency_code);
        $this->assertSame(1.0, round((float) $quotation->exchange_rate, 6));

        $auto = $quotation->lines->firstWhere('description', 'Auto');
        $manual = $quotation->lines->firstWhere('description', 'Hand');
        $this->assertSame(90.00, round((float) $auto->unit_price, 2), 'auto line re-priced into SGD = exactly −10% of catalogue');
        $this->assertSame(95.00, round((float) $manual->unit_price, 2), 'manual line value preserved');
        $this->assertSame(95.00, round((float) $manual->base_unit_price, 2), 'manual base restamped at new snapshot (SGD doc)');
    }

    // ════════════════════════════════════════════════════════════════════
    // FR-6.6 — delivery order + sales order surfaces support the list
    // ════════════════════════════════════════════════════════════════════

    public function test_delivery_order_inherits_currency_and_stands_alone_with_pricelist(): void
    {
        $product = $this->product(100.0);
        $list = $this->usdList($product);
        $this->customer->update(['price_list_id' => $list->id]);

        // Standalone DO → resolves USD from the customer's list (FR-5.6)
        $warehouse = \App\Models\Warehouse::factory()->create();
        $do = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity_delivered' => 3],
            ],
        ]);
        $do->assertCreated();

        $order = \Modules\Sales\Models\DeliveryOrder::with('lines')->find((int) $do->json('data.id'));
        $this->assertSame('USD', $order->currency_code, 'standalone DO resolves pricelist currency');
        $this->assertSame(1.350000, round((float) $order->exchange_rate, 6));

        $line = $order->lines->first();
        $this->assertNotNull($line);
        $this->assertSame(66.67, round((float) $line->unit_price, 2), 'DO line priced from the price list (FR-6.6)');
        $this->assertSame(90.00, round((float) $line->base_unit_price, 2));
        $this->assertSame($list->id, $line->pricing_trace['pricelist_id'] ?? null);
    }

    // ════════════════════════════════════════════════════════════════════
    // Editor contract — what the invoice/quotation UI actually calls
    // ════════════════════════════════════════════════════════════════════

    public function test_explicit_price_list_selection_wins_on_invoice_create(): void
    {
        // A global (all-customer) list the invoice editor can select — the
        // customer has NO default list, so only the explicit selection can
        // produce this price (FR-2.3 step 1).
        $product = $this->product(100.0);
        $list = PriceList::create([
            'name' => 'Tender SGD',
            'code' => 'TENDER-SGD',
            'currency_code' => 'SGD',
            'is_active' => true,
        ]);
        PriceListApplication::create(['price_list_id' => $list->id, 'applies_to_type' => 'all']);
        PriceListItem::create([
            'price_list_id' => $list->id,
            'sequence' => 10,
            'apply_on' => 'all',
            'compute_type' => 'fixed',
            'fixed_price' => 77.77,
        ]);

        // NOTE: this hits the same route the browser does; the editor sends
        // price_mode='auto' + the list id and the client-side unit_price is
        // IGNORED in favour of the engine (server-authoritative pricing).
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'price_list_id' => $list->id,
            'currency_code' => 'SGD',
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Widget',
                'quantity' => 2,
                'unit_price' => 999.99, // stale client value — must be replaced
                'price_mode' => 'auto',
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $invoice = Invoice::find((int) $response->json('id'));
        $line = $invoice->lines()->first();
        $this->assertSame(77.77, round((float) $line->unit_price, 2), 'explicit list rule prices the line');
        $this->assertSame('pricelist', $line->pricing_trace['source']);
        $this->assertSame($list->id, $line->pricing_trace['pricelist_id']);
        $this->assertSame('SGD', $invoice->currency_code);
    }

    public function test_resolve_batch_returns_the_editor_contract_shape(): void
    {
        $product = $this->product(100.0);
        $list = $this->usdList($product, percentOff: 10.0);
        $this->customer->update(['price_list_id' => $list->id]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/pricing/resolve-batch', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertOk();
        $data = $response->json('data');

        // Exactly the fields the editors consume.
        $this->assertSame('USD', $data['currency_code']);
        $this->assertSame('SGD', $data['base_currency_code']);
        $this->assertSame(1.35, round((float) $data['exchange_rate'], 2));
        $this->assertSame('Export USD', $data['price_list_name']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame(66.67, round((float) $data['lines'][0]['unit_price'], 2));
        $this->assertSame(90.0, round((float) $data['lines'][0]['base_unit_price'], 2));
        $this->assertSame($list->id, $data['lines'][0]['pricing_trace']['pricelist_id']);
    }

    public function test_document_lists_support_currency_filter_and_sort(): void
    {
        $product = $this->product(100.0);

        // One SGD (base) + one USD invoice.
        $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'SGD',
            'lines' => [['product_id' => $product->id, 'description' => 'A', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'lines' => [['product_id' => $product->id, 'description' => 'B', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        // FR-8.1 — filter.
        $filtered = $this->actingAs($this->admin)->getJson('/api/v1/invoices?currency_code=USD&per_page=50');
        $filtered->assertOk();
        $this->assertNotEmpty($filtered->json('data'));
        foreach ($filtered->json('data') as $row) {
            $this->assertSame('USD', $row['currency_code'], 'filter must only return USD invoices');
        }

        // FR-8.1 — sort by currency (ascending bucket).
        $sorted = $this->actingAs($this->admin)->getJson('/api/v1/invoices?sort_by=currency&per_page=50');
        $sorted->assertOk();
        $codes = collect($sorted->json('data'))->pluck('currency_code')->values()->all();
        $ascending = $codes;
        sort($ascending);
        $this->assertSame($ascending, $codes, 'sort_by=currency orders by currency code');

        // Quotations — same contract.
        $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'lines' => [['product_id' => $product->id, 'description' => 'Q USD', 'quantity' => 1, 'price_mode' => 'auto', 'tax_rate' => 0]],
        ])->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'currency_code' => 'SGD',
            'lines' => [['product_id' => $product->id, 'description' => 'Q SGD', 'quantity' => 1, 'price_mode' => 'auto', 'tax_rate' => 0]],
        ])->assertCreated();

        $quotes = $this->actingAs($this->admin)->getJson('/api/v1/quotations?currency_code=USD&per_page=50');
        $quotes->assertOk();
        $this->assertNotEmpty($quotes->json('data'));
        foreach ($quotes->json('data') as $row) {
            $this->assertSame('USD', $row['currency_code']);
        }

        // Delivery orders — currency_code also filterable.
        $warehouse = \App\Models\Warehouse::factory()->create();
        foreach (['SGD', 'USD'] as $currency) {
            $do = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $warehouse->id,
                'currency_code' => $currency,
                'lines' => [['product_id' => $product->id, 'description' => 'D '.$currency, 'quantity_delivered' => 1]],
            ]);
            $do->assertCreated();
            $this->assertSame($currency, \Modules\Sales\Models\DeliveryOrder::find((int) $do->json('data.id'))->currency_code);
        }

        $dos = $this->actingAs($this->admin)->getJson('/api/v1/sales/delivery-orders?currency_code=USD&per_page=50');
        $dos->assertOk();
        $this->assertNotEmpty($dos->json('data'));
        foreach ($dos->json('data') as $row) {
            $this->assertSame('USD', $row['currency_code']);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // FR-5.10 — legacy documents keep working (base, rate 1)
    // ════════════════════════════════════════════════════════════════════

    public function test_base_currency_invoice_posts_exactly_as_before(): void
    {
        $product = $this->product(100.0);

        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $invoiceResponse->assertCreated();
        $invoiceId = (int) $invoiceResponse->json('id');

        $invoice = Invoice::find($invoiceId);
        $this->assertSame('SGD', $invoice->currency_code);
        $this->assertSame(1.000000, round((float) $invoice->exchange_rate, 6));

        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post")->assertOk();
        $entry = \App\Models\JournalEntry::with('lines')->find(Invoice::find($invoiceId)->journal_entry_id);

        $ar = $entry->lines->first(fn ($l) => (float) $l->debit === 300.00);
        $this->assertNotNull($ar);
        // RAW column: the legacy getOriginalAmountAttribute accessor fabricates
        // debit/credit when the stored value is NULL, so read the DB directly.
        $storedOriginal = DB::table('journal_entry_lines')->where('id', $ar->id)->value('original_amount');
        $this->assertNull($storedOriginal, 'base postings carry no FX columns (backward compatible)');
        $this->assertNull($entry->original_currency_code);
    }
}
