<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Services\DataSource\PurchaseOrderDataSource;
use Tests\TenantTestCase;

/**
 * End-to-end pipeline: seeded default template + sample data → rendered output.
 * Exercises the same path the consumer /invoices/{id}/pdf endpoint uses.
 */
class PdfRenderPipelineTest extends TenantTestCase
{
    private PdfGenerationServiceContract $pdf;

    private DocumentTemplateServiceContract $templates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());

        $this->templates = app(DocumentTemplateServiceContract::class);
        $this->pdf = app(PdfGenerationServiceContract::class);

        $this->templates->seedDefaults();
    }

    public function test_render_invoice_html_contains_merged_values(): void
    {
        $result = $this->pdf->renderData(
            'invoice',
            (new InvoiceDataSource)->sample(),
            null,
            'html',
        );

        $this->assertSame('text/html', $result['mime']);
        $this->assertStringContainsString('INV-2026-0001', $result['html']);
        $this->assertStringContainsString('Globex Corporation', $result['html']);
        $this->assertStringContainsString('INVOICE', $result['html']);
        $this->assertStringContainsString('S$8,056.00', $result['html']);
    }

    public function test_render_invoice_sample_to_valid_pdf(): void
    {
        $result = $this->pdf->renderData(
            'invoice',
            (new InvoiceDataSource)->sample(),
            null,
            'pdf',
        );

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertNotEmpty($result['bytes']);
        // PDF files always start with the %PDF magic bytes.
        $this->assertStringStartsWith('%PDF', $result['bytes']);
    }

    public function test_foreign_currency_render_appends_dual_currency_footer(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['meta']['currency_code'] = 'USD';
        $data['meta']['base_currency_code'] = 'SGD';
        $data['meta']['exchange_rate'] = 1.3514;
        $data['totals']['total'] = 740.00;
        $data['totals']['base_total'] = 1000.00;

        $result = $this->pdf->renderData('invoice', $data, null, 'html');

        $this->assertStringContainsString('Exchange rate: 1 USD = 1.3514 SGD', $result['html'], 'FR-8.5 rate footnote');
        $this->assertStringContainsString('Base total:', $result['html']);
        $this->assertStringContainsString('1,000.00', $result['html']);
    }

    public function test_base_currency_render_has_no_fx_footer(): void
    {
        $result = $this->pdf->renderData('invoice', (new InvoiceDataSource)->sample(), null, 'html');

        $this->assertStringNotContainsString('Exchange rate:', $result['html'], 'base documents stay clean');
    }

    public function test_render_purchase_order_html_contains_merged_values(): void
    {
        $result = $this->pdf->renderData(
            'purchase_order',
            (new PurchaseOrderDataSource)->sample(),
            null,
            'html',
        );

        $this->assertSame('text/html', $result['mime']);
        $this->assertStringContainsString('PURCHASE ORDER', $result['html']);
        $this->assertStringContainsString('PO-20260708-0001', $result['html']);
        $this->assertStringContainsString('Initech Supplies', $result['html']);
        $this->assertStringContainsString('S$7,146.00', $result['html']);
    }

    public function test_render_hash_is_stable_for_identical_input(): void
    {
        $data = (new InvoiceDataSource)->sample();

        $a = $this->pdf->renderData('invoice', $data, null, 'html');
        $b = $this->pdf->renderData('invoice', $data, null, 'html');

        $this->assertSame($a['hash'], $b['hash']);
    }
}
