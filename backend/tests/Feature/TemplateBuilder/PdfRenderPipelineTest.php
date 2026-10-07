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

    /**
     * #1004: dompdf has no Times New Roman / Courier New, so the sanitizer's
     * same-class DejaVu fallback must make those lines render with a serif /
     * mono font in the real PDF instead of dompdf's default font.
     */
    public function test_rich_text_named_fonts_render_with_same_class_pdf_fonts(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['lines'] = [
            [
                'line_type' => 'note',
                'index' => null,
                'description' => 'Serif note',
                'description_html' => \App\Support\InvoiceRichtextSanitizer::clean('<p><span style="font-family:Times New Roman">Serif note</span></p>'),
            ],
            [
                'line_type' => 'note',
                'index' => null,
                'description' => 'Mono note',
                'description_html' => \App\Support\InvoiceRichtextSanitizer::clean('<p><span style="font-family:Courier New">Mono note</span></p>'),
            ],
        ];

        $result = $this->pdf->renderData('invoice', $data, null, 'pdf');

        $this->assertStringStartsWith('%PDF', $result['bytes']);
        preg_match_all('#/BaseFont\s*/([A-Za-z0-9+\-]+)#', $result['bytes'], $matches);
        $fonts = implode(' ', $matches[1]);

        // DejaVu (not the core Times/Courier fonts) is the fallback so non-Latin
        // text keeps its glyphs. Before the fallback stack, an unknown family
        // fell through to dompdf's default font instead.
        $this->assertMatchesRegularExpression('/DejaVuSerif/', $fonts, "Times New Roman line must use DejaVu Serif in the PDF; got: {$fonts}");
        $this->assertMatchesRegularExpression('/DejaVuSansMono/', $fonts, "Courier New line must use DejaVu Sans Mono in the PDF; got: {$fonts}");
    }
}
