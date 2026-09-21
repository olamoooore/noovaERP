<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #98 (BLOCKER) — ar_transactions / ap_transactions must report the
 * document's real currency, not a hardcoded 'USD'.
 *
 * The documents previously had no currency column at all (only payments.currency
 * existed and was never populated), so this covers both the schema/backfill and
 * the live behaviour:
 *   - new documents default to the tenant's company_settings.base_currency
 *   - an explicitly supplied currency is preserved
 *   - legacy/NULL values fall back to base currency, then USD
 *   - the rebuilt views expose that value for invoice/bill/payment rows
 */
class HR98ArApViewsReportRealCurrencyTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->assertTenantSchemaReady(['invoices', 'bills', 'payments', 'bill_payments']);

        foreach ([
            ['USD', 'US Dollar', '$'],
            ['MYR', 'Malaysian Ringgit', 'RM'],
            ['SGD', 'Singapore Dollar', 'S$'],
        ] as [$code, $name, $symbol]) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'symbol' => $symbol, 'decimal_places' => 2, 'is_active' => true]
            );
        }
    }

    private function setBaseCurrency(string $code): void
    {
        CompanySettings::query()->delete();
        CompanySettings::create(['base_currency' => $code]);
    }

    private function arRow(string $type, int $id): ?object
    {
        return DB::table('ar_transactions')
            ->where('transaction_type', $type)
            ->where('transaction_id', $id)
            ->first();
    }

    private function apRow(string $type, int $id): ?object
    {
        return DB::table('ap_transactions')
            ->where('transaction_type', $type)
            ->where('transaction_id', $id)
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_and_payment_report_the_tenant_base_currency(): void
    {
        $this->setBaseCurrency('MYR');

        $invoice = Invoice::factory()->posted()->create(['total' => 100, 'balance' => 100]);
        $payment = Payment::factory()->create(['amount' => 40, 'customer_id' => $invoice->customer_id]);
        $bill = Bill::factory()->posted()->create(['total' => 55, 'balance' => 55]);
        $billPayment = BillPayment::factory()->create(['amount' => 20, 'vendor_id' => $bill->vendor_id, 'bill_id' => $bill->id]);

        $this->assertSame('MYR', $invoice->fresh()->currency_code, 'New invoices default to base currency');
        $this->assertSame('MYR', $payment->fresh()->currency, 'New payments default to base currency');
        $this->assertSame('MYR', $bill->fresh()->currency_code, 'New bills default to base currency');
        $this->assertSame('MYR', $billPayment->fresh()->currency_code, 'New bill payments default to base currency');

        $this->assertSame('MYR', $this->arRow('invoice', $invoice->id)->currency_code);
        $this->assertSame('MYR', $this->arRow('payment', $payment->id)->currency_code);
        $this->assertSame('MYR', $this->apRow('bill', $bill->id)->currency_code);
        $this->assertSame('MYR', $this->apRow('bill_payment', $billPayment->id)->currency_code);
    }

    public function test_explicit_document_currency_is_reported_verbatim(): void
    {
        $this->setBaseCurrency('MYR');

        $invoice = Invoice::factory()->posted()->create([
            'total' => 100, 'balance' => 100, 'currency_code' => 'SGD',
        ]);
        $bill = Bill::factory()->posted()->create([
            'total' => 100, 'balance' => 100, 'currency_code' => 'SGD',
        ]);

        $this->assertSame('SGD', $this->arRow('invoice', $invoice->id)->currency_code);
        $this->assertSame('SGD', $this->apRow('bill', $bill->id)->currency_code);
        $this->assertNotSame('USD', $this->arRow('invoice', $invoice->id)->currency_code);
    }

    public function test_legacy_null_currency_falls_back_to_base_currency(): void
    {
        $this->setBaseCurrency('SGD');

        $invoice = Invoice::factory()->posted()->create(['total' => 10, 'balance' => 10]);
        $bill = Bill::factory()->posted()->create(['total' => 10, 'balance' => 10]);

        // Simulate a pre-migration row that the backfill could not classify.
        DB::table('invoices')->where('id', $invoice->id)->update(['currency_code' => null]);
        DB::table('bills')->where('id', $bill->id)->update(['currency_code' => null]);

        $this->assertSame('SGD', $this->arRow('invoice', $invoice->id)->currency_code);
        $this->assertSame('SGD', $this->apRow('bill', $bill->id)->currency_code);
    }

    public function test_without_company_settings_everything_falls_back_to_usd(): void
    {
        CompanySettings::query()->delete();

        $invoice = Invoice::factory()->posted()->create(['total' => 10, 'balance' => 10]);

        $this->assertSame('USD', $invoice->fresh()->currency_code);
        $this->assertSame('USD', $this->arRow('invoice', $invoice->id)->currency_code);
    }

    public function test_no_view_row_reports_usd_when_tenant_is_not_usd(): void
    {
        $this->setBaseCurrency('MYR');

        Invoice::factory()->posted()->create(['total' => 5, 'balance' => 5]);
        Bill::factory()->posted()->create(['total' => 5, 'balance' => 5]);

        $usdInvoices = DB::table('ar_transactions')->where('transaction_type', 'invoice')->where('currency_code', 'USD')->count();
        $usdBills = DB::table('ap_transactions')->where('transaction_type', 'bill')->where('currency_code', 'USD')->count();

        $this->assertSame(0, $usdInvoices, 'No invoice row may report USD for a MYR tenant');
        $this->assertSame(0, $usdBills, 'No bill row may report USD for a MYR tenant');
    }
}
