<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #880 (SA7): the FX rounding residual on a foreign-currency invoice
 * must post to the FX/rounding account role (MRD pricelist-multicurrency
 * FR-7.4: "posted to the same FX account (or a rounding account if
 * configured)"), with the revenue line carrying its exact converted amount —
 * never absorbed silently at revenue.
 */
class InvoiceFxRoundingResidualTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ChartOfAccount $revenue;

    private ChartOfAccount $fxGain;

    private ChartOfAccount $fxLoss;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'fx-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CompanySettings::query()->create([
            'company_name' => 'FX Residual Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'FX Residual Group',
            'code' => 'FX-RES-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'FX Residual Entity',
            'code' => 'FX-RES-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'FX Residual Widget',
            'sku' => 'FX-880-'.Str::upper(Str::random(6)),
            'price' => 100.01,
            'cost' => 60,
            'track_inventory' => false,
        ]);

        $this->seedAccountsAndRate();
    }

    public function test_usd_rounding_residual_posts_to_the_fx_account_not_revenue(): void
    {
        // 100.01 USD subtotal + 1.00 USD shipping at 1.3527:
        //   AR base        = round(101.01 × 1.3527, 2) = 136.64
        //   Revenue base   = round(100.01 × 1.3527, 2) = 135.28
        //   Shipping base  = round(  1.00 × 1.3527, 2) =   1.35
        //   residual = 136.64 − 135.28 − 1.35 = 0.01 → FX account.
        $invoice = $this->createUsdInvoice();

        DB::table('invoices')->where('id', $invoice->id)->update([
            'shipping_cost' => 1.00,
            'total' => 101.01,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $revenueLine = $lines->firstWhere('account_id', $this->revenue->id);
        $this->assertNotNull($revenueLine, 'The revenue line must exist.');
        $this->assertEqualsWithDelta(
            135.28,
            (float) $revenueLine->credit,
            0.001,
            'The revenue line must carry its exact converted amount — the 0.01 residual must not be absorbed at revenue.'
        );

        $fxLine = $lines->first(fn ($line) => (int) $line->account_id === $this->fxGain->id
            || (int) $line->account_id === $this->fxLoss->id);
        $this->assertNotNull($fxLine, 'The FX rounding residual must post to the FX gain/loss account.');
        $this->assertEqualsWithDelta(
            0.01,
            abs((float) $fxLine->debit - (float) $fxLine->credit),
            0.0001,
            'The FX rounding account must carry exactly the 0.01 residual.'
        );

        $debits = $lines->sum(fn ($line) => (float) $line->debit);
        $credits = $lines->sum(fn ($line) => (float) $line->credit);
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'The journal entry must stay balanced.');
    }

    private function createUsdInvoice(): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'FX Residual Test Line',
                'quantity' => 1,
                'unit_price' => 100.01,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated($response->getContent());

        return Invoice::findOrFail($response->json('id'));
    }

    private function seedAccountsAndRate(): void
    {
        $ar = ChartOfAccount::factory()->create(['code' => '880-AR', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => '880-REV', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $shipping = ChartOfAccount::factory()->create(['code' => '880-SHIP', 'name' => 'Shipping Revenue', 'type' => 'revenue', 'is_active' => true]);
        $this->fxGain = ChartOfAccount::factory()->create(['code' => '880-FXG', 'name' => 'FX Gain', 'type' => 'expense', 'is_active' => true]);
        $this->fxLoss = ChartOfAccount::factory()->create(['code' => '880-FXL', 'name' => 'FX Loss', 'type' => 'expense', 'is_active' => true]);

        // The tenant migrations auto-seed default mappings (possibly several
        // NULL-document_type rows — Postgres UNIQUE treats NULLs as distinct),
        // so make each role deterministic for this fixture.
        $mappings = [
            'accounts_receivable' => $ar->id,
            'sales_revenue' => $this->revenue->id,
            'shipping_revenue' => $shipping->id,
            'realized_fx_gain' => $this->fxGain->id,
            'realized_fx_loss' => $this->fxLoss->id,
        ];

        foreach ($mappings as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('currencies')->updateOrInsert(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'MYR',
            'rate' => 1.3527,
            'effective_date' => now()->subDay()->toDateString(),
            'source' => 'manual',
        ]);
    }
}
