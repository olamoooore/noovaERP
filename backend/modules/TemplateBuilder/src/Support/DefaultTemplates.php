<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Support;

/**
 * Default (starter) template definitions seeded into every tenant.
 *
 * Each definition is a full DocumentTemplate row payload, with a `layout_config`
 * block tree that the BlockRenderer understands. Tenants can then customise.
 */
final class DefaultTemplates
{
    /**
     * @return array<int, array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::invoice(),
            self::quotation(),
            self::purchaseOrder(),
            self::receipt(),
        ];
    }

    /** @return array<string,mixed> */
    public static function invoice(): array
    {
        return [
            'document_type' => 'invoice',
            'code' => 'INV-TPL-DEFAULT',
            'name' => 'Default Invoice',
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">INVOICE</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Bill To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ invoice.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Invoice #:</b> {{ invoice.number }}<br/><b>Date:</b> {{ invoice.issue_date }}<br/><b>Due:</b> {{ invoice.due_date }}<br/><b>Reference:</b> {{ invoice.reference }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '46%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '12%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'conditional', 'condition' => '{{ totals.discount }} > 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Discount', 'field' => 'totals.discount', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total Due', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    ['type' => 'conditional', 'condition' => '{{ totals.discount }} <= 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total Due', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ invoice.terms }}'],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function quotation(): array
    {
        $tpl = self::invoice();
        $tpl['document_type'] = 'quotation';
        $tpl['code'] = 'QT-TPL-DEFAULT';
        $tpl['name'] = 'Default Quotation';
        $blocks = &$tpl['layout_config']['blocks'];
        $blocks[1]['children'][1]['content'] = '<div style="font-size:24px;font-weight:bold;color:#111827;">QUOTATION</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>';
        $blocks[3]['children'][1]['content'] = '<b>Quote #:</b> {{ invoice.number }}<br/><b>Date:</b> {{ invoice.issue_date }}<br/><b>Valid Until:</b> {{ invoice.due_date }}';

        return $tpl;
    }

    /** @return array<string,mixed> */
    public static function purchaseOrder(): array
    {
        return [
            'document_type' => 'purchase_order',
            'code' => 'PO-TPL-DEFAULT',
            'name' => 'Default Purchase Order',
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">PURCHASE ORDER</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Vendor</b><br/><b>{{ vendor.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ vendor.address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ vendor.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>PO #:</b> {{ po.number }}<br/><b>Date:</b> {{ po.order_date }}<br/><b>Expected:</b> {{ po.expected_date }}<br/><b>Deliver To:</b> {{ warehouse.name }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '48%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '12%', 'align' => 'right'],
                        ['label' => 'Unit Cost', 'field' => 'unit_price', 'width' => '17%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '17%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                        ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                        ['label' => 'Shipping', 'field' => 'totals.shipping', 'format' => 'money'],
                        ['label' => 'Total', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ po.notes }}'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function receipt(): array
    {
        return [
            'document_type' => 'receipt',
            'code' => 'RCT-TPL-DEFAULT',
            'name' => 'Default Payment Receipt',
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'text', 'align' => 'center', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                    ['type' => 'divider'],
                    ['type' => 'text', 'align' => 'center', 'content' => '<div style="font-size:22px;font-weight:bold;">PAYMENT RECEIPT</div>'],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'text', 'content' => 'Received from: <b>{{ customer.name }}</b>'],
                    ['type' => 'text', 'content' => 'Receipt #: <b>{{ receipt.number }}</b> &nbsp; Date: {{ receipt.date }}'],
                    ['type' => 'text', 'content' => 'Method: {{ receipt.method }} &nbsp; Reference: {{ receipt.reference }}'],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Amount Received', 'field' => 'totals.amount', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => 'Applied to invoice {{ receipt.invoice_no }}. Thank you for your payment.'],
                ],
            ],
        ];
    }
}
