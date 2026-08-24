<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Renderers\BlockRenderer;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Services\MergeFieldResolver;
use Tests\TestCase;

class BlockRendererTest extends TestCase
{
    private function renderer(): BlockRenderer
    {
        return new BlockRenderer(new MergeFieldResolver);
    }

    private function template(array $blocks): DocumentTemplate
    {
        $tpl = new DocumentTemplate;
        $tpl->mode = DocumentTemplate::MODE_BLOCK;
        $tpl->layout_config = ['blocks' => $blocks];

        return $tpl;
    }

    public function test_renders_text_block_with_merge_tokens(): void
    {
        $html = $this->renderer()->render(
            $this->template([['type' => 'text', 'content' => 'Invoice {{invoice.number}}']]),
            (new InvoiceDataSource)->sample(),
        )['html'];

        $this->assertStringContainsString('INV-2026-0001', $html);
    }

    public function test_renders_table_block_from_lines_source(): void
    {
        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'table', 'source' => 'lines',
                'columns' => [
                    ['label' => '#', 'field' => 'index'],
                    ['label' => 'Description', 'field' => 'description'],
                    ['label' => 'Amount', 'field' => 'line_total', 'format' => 'money'],
                ],
            ]]),
            (new InvoiceDataSource)->sample(),
        )['html'];

        $this->assertStringContainsString('Enterprise licence', $html);
        $this->assertStringContainsString('S$4,770.00', $html);
        $this->assertStringContainsString('<th', $html);
    }

    public function test_renders_totals_block(): void
    {
        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'totals', 'rows' => [
                    ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                    ['label' => 'Total', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                ],
            ]]),
            (new InvoiceDataSource)->sample(),
        )['html'];

        $this->assertStringContainsString('S$7,600.00', $html);
        $this->assertStringContainsString('S$8,056.00', $html);
        $this->assertStringContainsString('font-weight:bold', $html);
    }

    public function test_renders_case_information_as_a_table(): void
    {
        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'case_information',
                'title' => 'Case Information',
                'rows' => [
                    ['label' => 'Case Number', 'field' => 'invoice.case_number'],
                    ['label' => 'Date of Case', 'field' => 'invoice.date_of_case', 'format' => 'date'],
                    ['label' => 'Patient Name', 'field' => 'invoice.patient_name'],
                ],
            ]]),
            (new InvoiceDataSource)->sample(),
        )['html'];

        $this->assertStringContainsString('tb-information-table', $html);
        $this->assertStringNotContainsString('Case Information', $html);
        $this->assertStringContainsString('CASE-2026-001', $html);
        $this->assertStringContainsString('Sample Patient', $html);
        $this->assertStringNotContainsString('colspan=', $html);
        $this->assertSame(3, substr_count($html, '<th style='));
        $this->assertSame(3, substr_count($html, '<td style='));
        $this->assertStringContainsString('<table', $html);
    }

    public function test_renders_delivery_detail_with_default_fields(): void
    {
        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'delivery_detail',
                'title' => 'Delivery Detail',
                'rows' => [
                    ['label' => 'Salesperson', 'field' => 'delivery_order.salesperson'],
                    ['label' => 'PO Number', 'field' => 'delivery_order.po_number'],
                    ['label' => 'Invoice No', 'field' => 'invoice.number'],
                    ['label' => 'Quotation No', 'field' => 'quotation.number'],
                    ['label' => 'Payment Terms', 'field' => 'delivery_order.payment_terms'],
                    ['label' => 'Due Date', 'field' => 'delivery_order.due_date', 'format' => 'date'],
                ],
            ]]),
            (new \Modules\TemplateBuilder\Services\DataSource\DeliveryOrderDataSource)->sample(),
        )['html'];

        $this->assertStringNotContainsString('Delivery Detail', $html);
        $this->assertStringContainsString('John Smith', $html);
        $this->assertStringContainsString('INV-2026-0001', $html);
        $this->assertStringContainsString('Net 30', $html);
        $this->assertStringNotContainsString('colspan=', $html);
    }

    public function test_conditional_block_shows_when_condition_true(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['totals']['discount'] = 100.00;

        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'conditional', 'condition' => '{{ totals.discount }} > 0',
                'children' => [['type' => 'text', 'content' => 'DISCOUNT APPLIED']],
            ]]),
            $data,
        )['html'];

        $this->assertStringContainsString('DISCOUNT APPLIED', $html);
    }

    public function test_conditional_block_hides_when_condition_false(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['totals']['discount'] = 0;

        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'conditional', 'condition' => '{{ totals.discount }} > 0',
                'children' => [['type' => 'text', 'content' => 'DISCOUNT APPLIED']],
            ]]),
            $data,
        )['html'];

        $this->assertStringNotContainsString('DISCOUNT APPLIED', $html);
    }

    public function test_columns_block_renders_side_by_side(): void
    {
        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'columns', 'gap' => 8, 'children' => [
                    ['type' => 'text', 'content' => 'LEFT: {{customer.name}}'],
                    ['type' => 'text', 'align' => 'right', 'content' => 'RIGHT: {{invoice.number}}'],
                ],
            ]]),
            (new InvoiceDataSource)->sample(),
        )['html'];

        $this->assertStringContainsString('Globex Corporation', $html);
        $this->assertStringContainsString('INV-2026-0001', $html);
        $this->assertStringContainsString('<td', $html);
    }

    public function test_paginated_table_emits_rows_for_physical_page_measurement(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['lines'] = [
            ['index' => 1, 'description' => 'Line 1', 'line_total' => 10],
            ['index' => 2, 'description' => 'Line 2', 'line_total' => 20],
            ['index' => 3, 'description' => 'Line 3', 'line_total' => 30],
            ['index' => 4, 'description' => 'Line 4', 'line_total' => 40],
            ['index' => 5, 'description' => 'Line 5', 'line_total' => 50],
        ];

        $html = $this->renderer()->render(
            $this->template([
                ['type' => 'header', 'children' => [['type' => 'text', 'content' => 'PAGE HEADER']]],
                [
                    'type' => 'table',
                    'source' => 'lines',
                    'paginate' => true,
                    'subtotal_field' => 'line_total',
                    'subtotal_label' => 'Products on this page',
                    'columns' => [
                        ['label' => '#', 'field' => 'index'],
                        ['label' => 'Description', 'field' => 'description'],
                        ['label' => 'Amount', 'field' => 'line_total', 'format' => 'money'],
                    ],
                ],
                ['type' => 'totals', 'rows' => [['label' => 'Grand Total', 'field' => 'totals.total', 'format' => 'money']]],
                ['type' => 'footer', 'children' => [['type' => 'text', 'content' => 'FINAL FOOTER']]],
            ]),
            $data,
        )['html'];

        $this->assertStringNotContainsString('1/3', $html);
        $this->assertStringNotContainsString('Products on this page', $html);
        $this->assertSame(1, substr_count($html, 'PAGE HEADER'));
        $this->assertSame(1, substr_count($html, 'FINAL FOOTER'));
        $this->assertSame(1, substr_count($html, 'Grand Total'));
        $this->assertSame(5, substr_count($html, 'data-tb-line-index='));
        $this->assertStringContainsString('data-tb-line-amount="50"', $html);
        $this->assertStringContainsString('tb-document-footer', $html);
        $this->assertStringContainsString('tb-final-summary', $html);
        $this->assertTrue(strpos($html, 'FINAL FOOTER') < strpos($html, 'Grand Total'));
    }

    public function test_single_page_content_does_not_embed_a_synthetic_page_number(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['lines'] = [['index' => 1, 'description' => 'Only line', 'line_total' => 25]];

        $html = $this->renderer()->render(
            $this->template([
                ['type' => 'table', 'source' => 'lines', 'columns' => [
                    ['label' => 'Description', 'field' => 'description'],
                    ['label' => 'Amount', 'field' => 'line_total'],
                ]],
                ['type' => 'footer', 'children' => [['type' => 'text', 'content' => 'ONE PAGE FOOTER']]],
            ]),
            $data,
        )['html'];

        $this->assertStringNotContainsString('1/1', $html);
        $this->assertSame(1, substr_count($html, 'ONE PAGE FOOTER'));
        $this->assertSame(1, substr_count($html, 'data-tb-line-index='));
    }

    public function test_renderer_does_not_split_rows_by_a_predefined_count(): void
    {
        $data = (new InvoiceDataSource)->sample();
        $data['lines'] = collect(range(1, 9))->map(fn (int $index) => [
            'index' => $index,
            'description' => "Product {$index}",
            'line_total' => $index * 10,
        ])->all();

        $html = $this->renderer()->render(
            $this->template([[
                'type' => 'table',
                'source' => 'lines',
                'columns' => [
                    ['label' => '#', 'field' => 'index'],
                    ['label' => 'Description', 'field' => 'description'],
                    ['label' => 'Amount', 'field' => 'line_total'],
                ],
            ]]),
            $data,
        )['html'];

        $this->assertStringNotContainsString('1/2', $html);
        $this->assertStringNotContainsString('Page Subtotal', $html);
        $this->assertSame(9, substr_count($html, 'data-tb-line-index='));
        $this->assertSame(1, substr_count($html, 'Product 7'));
        $this->assertSame(1, substr_count($html, 'Product 8'));
        $this->assertLessThan(strpos($html, 'Product 8'), strpos($html, 'Product 7'));
    }
}
