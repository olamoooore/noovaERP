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
        $this->assertStringContainsString('RM 4,770.00', $html);
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

        $this->assertStringContainsString('RM 7,600.00', $html);
        $this->assertStringContainsString('RM 8,056.00', $html);
        $this->assertStringContainsString('font-weight:bold', $html);
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
}
