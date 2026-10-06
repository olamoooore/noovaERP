<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #521 (CN12) — a credit note against an invoice with no currency_code
 * (legacy/imported) fell back to a hard-coded 'USD'. On an MYR tenant that
 * booked the credit note as USD against an MYR base, triggering a foreign-
 * currency posting at a 1.0 rate.
 *
 * Contract: the fallback is the tenant's base currency (DocumentCurrencyResolver),
 * never a literal, so the credit note is in MYR and posts non-foreign.
 */
class CreditNoteBaseCurrencyFallbackTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '52152152-1521-4521-8521-521521521521',
        ]));
        $this->bindTenantContext($tenant->id);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
            'company_settings',
        ]);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CompanySettings::query()->delete();
        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN12', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN12', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN12', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function legacyInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Legacy item',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        // Legacy/imported invoice: no document currency, no FX snapshot.
        DB::table('invoices')->where('id', $invoice->id)->update([
            'currency_code' => null,
            'exchange_rate' => null,
            'base_currency_code' => null,
        ]);

        return $invoice->fresh(['lines']);
    }

    public function test_credit_note_on_invoice_without_currency_uses_tenant_base_and_posts_non_foreign(): void
    {
        $invoice = $this->legacyInvoice();
        $this->assertNull($invoice->currency_code, 'fixture must be a currency-less legacy invoice');

        $line = $invoice->lines->first();

        $response = $this->actingAs($this->user)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN12 legacy invoice',
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);
        $response->assertCreated();

        $cnId = (int) $response->json('id');
        $cn = DB::table('customer_credit_notes')->where('id', $cnId)->first();

        $this->assertSame('MYR', $cn->currency_code, 'credit note must fall back to the tenant base currency, not USD');
        $this->assertSame('MYR', $cn->base_currency_code);

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$cnId}/post")
            ->assertOk();

        $entryId = (int) DB::table('customer_credit_notes')->where('id', $cnId)->value('journal_entry_id');
        $this->assertGreaterThan(0, $entryId);

        $foreignLines = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->whereNotNull('original_amount')
            ->count();
        $this->assertSame(0, $foreignLines, 'an MYR credit note on an MYR tenant must post non-foreign (no original-currency legs)');

        $header = DB::table('journal_entries')->where('id', $entryId)->first();
        $this->assertNotSame('USD', $header->original_currency_code ?? null, 'the ledger must not book the credit note as USD');

        $sums = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta(100.0, (float) $sums->d, 0.01);
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01, 'JE must balance');
    }
}
