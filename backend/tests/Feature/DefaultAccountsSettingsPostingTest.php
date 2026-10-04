<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #877 (SA3) — Settings > Default Accounts wrote company_settings
 * .default_* columns, but posting resolves accounts through account_mappings.
 * Changing the revenue account on the screen therefore had no effect on
 * postings while the UI reported success.
 *
 * Real HTTP through the router: the settings screen writes, an invoice is
 * created and posted, and the revenue journal line must hit the account the
 * screen selected.
 */
class DefaultAccountsSettingsPostingTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Default Accounts 877 Tenant');
    }

    private function postInvoice(float $unitPrice = 100): Invoice
    {
        $customer = Customer::factory()->create(['name' => 'SA3 Customer']);
        $product = Product::factory()->create([
            'name' => 'SA3 Widget',
            'price' => $unitPrice,
            'cost' => 10,
            'track_inventory' => false,
        ]);

        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'SA3 revenue line',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated();

        $invoiceId = (int) $create->json('id');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/invoices/'.$invoiceId.'/send')
            ->assertOk();

        return Invoice::with(['journalEntry.lines'])->findOrFail($invoiceId);
    }

    public function test_revenue_account_chosen_on_settings_screen_is_used_by_invoice_posting(): void
    {
        // The lazy seeder created the well-known 4100 revenue account; the
        // tenant picks a different one on the Default Accounts screen.
        $legacyRevenue = ChartOfAccount::where('code', '4100')->firstOrFail();
        $chosenRevenue = ChartOfAccount::create([
            'code' => '4199',
            'name' => 'Tenant Chosen Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/settings/accounts', [
                'sales_revenue' => $chosenRevenue->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.sales_revenue', $chosenRevenue->id);

        // The screen must read back the mapping posting actually uses.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/settings/accounts')
            ->assertOk()
            ->assertJsonPath('data.sales_revenue', $chosenRevenue->id);

        $this->assertSame(
            $chosenRevenue->id,
            (int) AccountMapping::where('mapping_key', 'sales_revenue')
                ->whereNull('document_type')
                ->value('account_id'),
            'the settings screen must write the account_mappings row posting resolves'
        );

        $invoice = $this->postInvoice(100);

        $revenueLine = $invoice->journalEntry->lines
            ->firstWhere('account_id', $chosenRevenue->id);

        $this->assertNotNull(
            $revenueLine,
            'the invoice revenue line must post to the account selected in Settings > Default Accounts'
        );
        $this->assertGreaterThan(0, (float) $revenueLine->credit);
        $this->assertNull(
            $invoice->journalEntry->lines->firstWhere('account_id', $legacyRevenue->id),
            'the legacy 4100 revenue account must not be used once another account is configured'
        );
    }

    public function test_default_accounts_read_does_not_silently_create_company_settings(): void
    {
        DB::table('company_settings')->delete();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/settings/accounts')
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('company_settings')->count(),
            'reading default accounts must not auto-create a company_settings row with a silent base currency'
        );
    }
}
