<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\CompanySettings;
use App\Models\Invoice;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Support\StatutoryDocumentFields;
use Tests\TenantTestCase;

/**
 * #1019 — the seeded default invoice, rendered through the real render path
 * (seedDefaults → template resolution → BlockRenderer), must carry the IRAS
 * tax-invoice list for a GST-registered Singapore supplier:
 *
 * IRAS "Invoicing customers"
 * (https://www.iras.gov.sg/taxes/goods-services-tax-(gst)/basics-of-gst/invoicing-price-display-and-record-keeping/invoicing-customers):
 * "A tax invoice must contain ... The words, "Tax Invoice"; ... GST
 * registration number; ... Total amount payable excluding GST; Total GST;
 * Total amount payable including GST", and for a foreign-currency invoice
 * those three totals must be converted into Singapore dollars.
 */
class DefaultInvoiceIrasTaxInvoiceTest extends TenantTestCase
{
    private PdfGenerationServiceContract $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());

        app(DocumentTemplateServiceContract::class)->seedDefaults();
        $this->pdf = app(PdfGenerationServiceContract::class);
    }

    /**
     * Sample invoice payload with an SG (GST regime) company block whose
     * labels come from the SG country pack.
     *
     * @return array<string,mixed>
     */
    private function sgInvoiceData(string $gstNumber): array
    {
        $fields = StatutoryDocumentFields::forJurisdiction('SG');

        $data = (new InvoiceDataSource)->sample();
        $data['company'] = array_merge($data['company'], [
            'name' => 'Acme Sample Pte Ltd',
            'tax_regime' => 'GST',
            'tin' => '201626174G',
            'tin_label' => $fields['tin']['label'],
            'gst_number' => $gstNumber,
            'gst_number_label' => $fields['gst_number']['label'],
            'sst_number' => '',
        ]);

        return $data;
    }

    private function html(string $type, array $data): string
    {
        return (string) $this->pdf->renderData($type, $data, null, 'html')['html'];
    }

    public function test_gst_registered_supplier_prints_tax_invoice_title_gst_number_and_uen(): void
    {
        $html = $this->html('invoice', $this->sgInvoiceData('M21234567K'));

        $this->assertStringContainsString('TAX INVOICE', $html);
        $this->assertStringContainsString('GST Registration No.: M21234567K', $html);
        $this->assertStringContainsString('Unique Entity Number (UEN): 201626174G', $html);
    }

    public function test_supplier_without_gst_number_prints_invoice_and_no_blank_gst_line(): void
    {
        $html = $this->html('invoice', $this->sgInvoiceData(''));

        $this->assertStringContainsString('INVOICE', $html);
        $this->assertStringNotContainsString('TAX INVOICE', $html);
        $this->assertStringNotContainsString('GST Registration No.:', $html, 'no blank GST registration line');
        $this->assertStringContainsString('Unique Entity Number (UEN): 201626174G', $html);
    }

    public function test_acceptance_copy_of_a_gst_invoice_is_not_titled_tax_invoice(): void
    {
        $data = $this->sgInvoiceData('M21234567K');
        $data['document_variant'] = 'ACCEPTANCE COPY — NOT A TAX INVOICE';

        $html = $this->html('invoice', $data);

        // The only "TAX INVOICE" text left is inside the "NOT A TAX INVOICE" banner.
        $this->assertSame(1, substr_count($html, 'TAX INVOICE'));
        $this->assertStringContainsString('NOT A TAX INVOICE', $html);
    }

    public function test_foreign_currency_invoice_prints_sgd_equivalents_at_the_invoice_rate(): void
    {
        // Document (USD) → base (SGD): base = round(document amount × rate, 2),
        // the same direction InvoiceService::buildPostingRequest() books.
        $invoice = Invoice::factory()->create([
            'currency_code' => 'USD',
            'base_currency_code' => 'SGD',
            'exchange_rate' => 1.3514,
            'subtotal' => 1050.00,
            'discount_amount' => 50.00,
            'tax_amount' => 90.00,
            'total' => 1090.00,
            'balance' => 1090.00,
        ]);

        $data = (new InvoiceDataSource)->load((string) $invoice->id)->toArray();

        // 1000.00 × 1.3514 = 1351.40; 90.00 × 1.3514 = 121.626 → 121.63;
        // 1090.00 × 1.3514 = 1473.026 → 1473.03.
        $this->assertSame(1351.40, $data['totals']['base_total_excl_tax']);
        $this->assertSame(121.63, $data['totals']['base_tax']);
        $this->assertSame(1473.03, $data['totals']['base_total']);

        $html = $this->html('invoice', $data);

        $this->assertStringContainsString('Amounts in SGD (at rate 1 USD = 1.3514 SGD)', $html);
        $this->assertMatchesRegularExpression('/Total amount payable excl\. GST.*?SGD 1,351\.40/s', $html);
        $this->assertMatchesRegularExpression('/Total GST.*?SGD 121\.63/s', $html);
        $this->assertMatchesRegularExpression('/Total amount payable incl\. GST.*?SGD 1,473\.03/s', $html);
    }

    public function test_base_currency_invoice_prints_no_sgd_block(): void
    {
        $invoice = Invoice::factory()->create([
            'currency_code' => 'SGD',
            'base_currency_code' => 'SGD',
            'exchange_rate' => 1.0,
            'subtotal' => 1000.00,
            'tax_amount' => 90.00,
            'total' => 1090.00,
            'balance' => 1090.00,
        ]);

        $html = $this->html('invoice', (new InvoiceDataSource)->load((string) $invoice->id)->toArray());

        $this->assertStringNotContainsString('Amounts in SGD', $html);
    }

    /**
     * The tenant's GST registration number (Settings → General → Tax &
     * Registration, company_settings.gst_registration_number) must reach an
     * entity-scoped invoice whose legal entity carries no registration number
     * of its own — otherwise a GST-registered supplier prints neither its GST
     * number nor "Tax Invoice".
     */
    public function test_entity_scoped_invoice_falls_back_to_the_tenant_gst_registration_number(): void
    {
        CompanySettings::query()->create([
            'company_name' => 'Fallback GST Pte Ltd',
            'base_currency' => 'SGD',
            'tax_id' => '201626174G',
            'gst_registration_number' => 'M21234567K',
        ]);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'IRAS Group',
            'code' => 'IRG-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entity = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'IRAS Entity',
            'code' => 'IRE-'.Str::upper(Str::random(4)),
            'status' => 'active',
            'country_code' => 'SG',
        ]);

        $invoice = Invoice::factory()->create([
            'legal_entity_id' => $entity->id,
            'currency_code' => 'SGD',
            'base_currency_code' => 'SGD',
            'exchange_rate' => 1.0,
            'subtotal' => 100.00,
            'tax_amount' => 9.00,
            'total' => 109.00,
            'balance' => 109.00,
        ]);

        $data = (new InvoiceDataSource)->load((string) $invoice->id)->toArray();

        $this->assertSame('M21234567K', $data['company']['gst_number']);

        $html = $this->html('invoice', $data);

        $this->assertStringContainsString('TAX INVOICE', $html);
        $this->assertStringContainsString('GST Registration No.: M21234567K', $html);
        $this->assertStringContainsString('Unique Entity Number (UEN): 201626174G', $html);
    }

    public function test_default_quotation_never_says_tax_invoice(): void
    {
        $html = $this->html('quotation', $this->sgInvoiceData('M21234567K'));

        $this->assertStringContainsString('QUOTATION', $html);
        $this->assertStringNotContainsString('TAX INVOICE', $html);
    }

    /**
     * The block evaluator compares one token with one literal (no "&&"), so
     * the default invoice must print exactly one totals block — a single set
     * of total excl. tax / tax / total incl. tax.
     */
    public function test_unpaid_invoice_prints_exactly_one_totals_block(): void
    {
        $noDiscount = $this->sgInvoiceData('M21234567K');
        $noDiscount['totals']['discount'] = 0.0;
        $html = $this->html('invoice', $noDiscount);
        $this->assertSame(1, substr_count($html, 'Total Due'), 'no-discount invoice');
        $this->assertStringNotContainsString('>Discount<', $html);

        $withDiscount = $this->sgInvoiceData('M21234567K');
        $html = $this->html('invoice', $withDiscount);
        $this->assertSame(1, substr_count($html, 'Total Due'), 'discounted invoice');
        $this->assertStringContainsString('Discount', $html);

        $paid = $this->sgInvoiceData('M21234567K');
        $paid['totals']['paid'] = 1000.0;
        $paid['totals']['balance'] = 7056.0;
        $html = $this->html('invoice', $paid);
        $this->assertSame(0, substr_count($html, 'Total Due'), 'paid invoice shows Balance Due instead');
        $this->assertSame(1, substr_count($html, 'Balance Due'));
    }

    public function test_rendered_default_invoice_carries_the_signature_and_stamp_block(): void
    {
        $html = $this->html('invoice', $this->sgInvoiceData('M21234567K'));

        $this->assertStringContainsString('Authorised Signature', $html);
        $this->assertStringContainsString('Received By (Name / Date)', $html);
        $this->assertStringContainsString('Company Stamp', $html);
    }
}
