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
            self::deliveryOrder(),
            self::customerStatement(),
            // Issue #615 (TP5): sales order family + credit note now have data
            // sources and render routes, so they are editor-visible with a
            // starter each.
            self::salesOrder(),
            self::orderConfirmation(),
            self::proformaInvoice(),
            self::creditNote(),
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
                        ['type' => 'columns', 'gap' => 4, 'children' => [
                            ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'left'],
                            ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
                        ]],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">INVOICE</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Bill To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ invoice.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.tax_id }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Invoice #:</b> {{ invoice.number }}<br/><b>Date:</b> {{ invoice.issue_date }}<br/><b>Due:</b> {{ invoice.due_date }}<br/><b>Reference:</b> {{ invoice.reference }}'],
                    ]],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Ship To</b><br/><span style="font-size:10px;color:#374151;">{{ invoice.shipping_address }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '{{ invoice.shipping_method }}'],
                    ]],
                    // Issue #333: Incoterm (free-text code + place stored on
                    // the document) prints when set; hidden when unset.
                    ['type' => 'conditional', 'condition' => "{{ invoice.incoterm_code }} != ''", 'children' => [
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Incoterm:</b> {{ invoice.incoterm_code }} &mdash; {{ invoice.incoterm_place }}'],
                    ]],
                    // Issue #617: an acceptance copy is not a tax invoice. The
                    // render service supplies this token (acceptance_copy →
                    // "ACCEPTANCE COPY — NOT A TAX INVOICE"); it is empty for
                    // the canonical final invoice, so the banner is hidden.
                    ['type' => 'conditional', 'condition' => "{{ document_variant }} != ''", 'children' => [
                        ['type' => 'text', 'align' => 'center', 'content' => '<div style="border:2px solid #b91c1c;color:#b91c1c;font-weight:bold;letter-spacing:2px;padding:6px;font-size:13px;">{{ document_variant }}</div>'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'paginate' => true, 'show_page_subtotal' => true, 'subtotal_label' => 'Page Subtotal', 'subtotal_field' => 'line_total', 'subtotal_format' => 'money', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '34%'],
                        ['label' => 'Lot/Batch', 'field' => 'lot_number', 'width' => '12%'],
                        ['label' => 'Expiry', 'field' => 'expiry_date', 'width' => '12%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '10%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '13%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '13%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    // Issue #614 (TP4): statutory tax summary per posted tax
                    // code/rate. paginate=false keeps its rows out of the
                    // line-item page subtotals.
                    ['type' => 'table', 'source' => 'tax_summary', 'paginate' => false, 'columns' => [
                        ['label' => 'Tax Code', 'field' => 'code', 'width' => '34%'],
                        ['label' => 'Rate (%)', 'field' => 'rate', 'width' => '22%', 'align' => 'right'],
                        ['label' => 'Taxable', 'field' => 'taxable', 'width' => '22%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Tax', 'field' => 'tax', 'width' => '22%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'conditional', 'condition' => '{{ totals.paid }} > 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total', 'field' => 'totals.total', 'format' => 'money'],
                            ['label' => 'Amount Paid', 'field' => 'totals.paid', 'format' => 'money'],
                            ['label' => 'Balance Due', 'field' => 'totals.balance', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    ['type' => 'conditional', 'condition' => '{{ totals.paid }} <= 0 && {{ totals.discount }} > 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Discount', 'field' => 'totals.discount', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total Due', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    ['type' => 'conditional', 'condition' => '{{ totals.paid }} <= 0 && {{ totals.discount }} <= 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total Due', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    // Issue #614 (TP4): amount in words in the document
                    // currency/language, then the bank details a payer needs.
                    ['type' => 'conditional', 'condition' => "{{ totals.amount_in_words }} != ''", 'children' => [
                        ['type' => 'text', 'size' => 9, 'color' => '#374151', 'content' => '<b>Amount in Words:</b> {{ totals.amount_in_words }}'],
                    ]],
                    ['type' => 'conditional', 'condition' => "{{ company.bank.account_number }} != ''", 'children' => [
                        ['type' => 'text', 'size' => 9, 'color' => '#374151', 'content' => '<b>Bank:</b> {{ company.bank.name }} · A/C Name: {{ company.bank.account_name }} · A/C No: {{ company.bank.account_number }} · SWIFT: {{ company.bank.swift_code }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ invoice.terms }}'],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }} · {{ company.website }}'],
                        // Issue #614: TIN is its own token, labelled by the
                        // document jurisdiction's country pack.
                        ['type' => 'conditional', 'condition' => "{{ company.tin }} != ''", 'children' => [
                            ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.tin_label }}: {{ company.tin }}'],
                        ]],
                        // Issue #614: the registration line follows the
                        // document jurisdiction's pack label (SST for MY,
                        // GST for SG, generic otherwise) instead of a
                        // hardcoded "GST Reg".
                        ['type' => 'conditional', 'condition' => "{{ company.tax_regime }} == 'GST'", 'children' => [
                            ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.gst_number_label }}: {{ company.gst_number }}'],
                        ]],
                        ['type' => 'conditional', 'condition' => "{{ company.tax_regime }} != 'GST'", 'children' => [
                            ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.sst_number_label }}: {{ company.sst_number }}'],
                        ]],
                        ['type' => 'conditional', 'condition' => "{{ einvoice.uuid }} != ''", 'children' => [
                            ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => 'E-Invoice: {{ einvoice.uuid }} · Validated: {{ einvoice.validated_at }}'],
                            ['type' => 'image', 'url' => '{{ einvoice.qr }}', 'width' => 70, 'align' => 'center'],
                        ]],
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
                        ['type' => 'columns', 'gap' => 4, 'children' => [
                            ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'left'],
                            ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
                        ]],
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
                    ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'center'],
                    ['type' => 'text', 'align' => 'center', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
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

    /** @return array<string,mixed> */
    public static function deliveryOrder(): array
    {
        return [
            'document_type' => 'delivery_order',
            'code' => 'DO-TPL-DEFAULT',
            'name' => 'Default Delivery Order',
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'columns', 'gap' => 4, 'children' => [
                            ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'left'],
                            ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
                        ]],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">DELIVERY ORDER</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Ship To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ delivery_order.shipping_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.phone }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>DO #:</b> {{ delivery_order.number }}<br/><b>Date:</b> {{ delivery_order.delivery_date }}<br/><b>Due Date:</b> {{ delivery_order.due_date }}<br/><b>Payment Terms:</b> {{ delivery_order.payment_terms }}<br/><b>Carrier:</b> {{ delivery_order.carrier }}<br/><b>Tracking:</b> {{ delivery_order.tracking_number }}<br/><b>Warehouse:</b> {{ warehouse.name }}<br/><b>SO Ref:</b> {{ sales_order.number }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'paginate' => false, 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '4%', 'align' => 'center'],
                        ['label' => 'SKU', 'field' => 'sku', 'width' => '12%'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '28%'],
                        ['label' => 'Ordered', 'field' => 'quantity_ordered', 'width' => '9%', 'align' => 'right'],
                        ['label' => 'Delivered', 'field' => 'quantity_delivered', 'width' => '9%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '13%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'UoM', 'field' => 'unit', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Lot/Batch', 'field' => 'lot_number', 'width' => '5%'],
                    ]],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                        ['label' => 'Total', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ delivery_order.notes }}'],
                    ['type' => 'spacer', 'height' => 30],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:9px;color:#6b7280;">Received by (Signature):</div><div style="border-top:1px solid #9ca3af;margin-top:40px;width:200px;"></div>'],
                        ['type' => 'text', 'content' => '<div style="font-size:9px;color:#6b7280;">Date:</div><div style="border-top:1px solid #9ca3af;margin-top:40px;width:150px;"></div>'],
                        ['type' => 'text', 'content' => '<div style="font-size:9px;color:#6b7280;">Delivered by (Signature):</div><div style="border-top:1px solid #9ca3af;margin-top:40px;width:200px;"></div>'],
                    ]],
                    ['type' => 'spacer', 'height' => 8],
                    // Plain blocks rather than a `footer` block: the renderer
                    // pins a `footer` + `totals` pair to the end of the document
                    // (right for invoices), which shoved the delivery order's
                    // Subtotal/Total *below* the signature row.
                    ['type' => 'divider'],
                    ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function customerStatement(): array
    {
        return [
            'document_type' => 'customer_statement',
            'code' => 'STMT-TPL-DEFAULT',
            'name' => 'Default Customer Statement',
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'columns', 'gap' => 4, 'children' => [
                            ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'left'],
                            ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
                        ]],
                        // Issue #616: a comparison is the same customer_statement
                        // document; only the heading and body differ.
                        ['type' => 'conditional', 'condition' => '{{ statement.period_type }} != comparison', 'align' => 'right', 'children' => [
                            ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">ACCOUNT STATEMENT</div>'],
                        ]],
                        ['type' => 'conditional', 'condition' => '{{ statement.period_type }} == comparison', 'align' => 'right', 'children' => [
                            ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">STATEMENT OF ACCOUNT — COMPARISON</div>'],
                        ]],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Customer</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#6b7280;">{{ customer.code }} · {{ customer.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Statement #:</b> {{ statement.number }}<br/><b>Period:</b> {{ statement.period_start }} to {{ statement.period_end }}<br/><b>Generated:</b> {{ statement.generated_at }}'],
                    ]],
                    // Issue #616: comparison payload variant — rendered rows
                    // come from the CustomerStatement data source, so the
                    // tenant's own template branding applies.
                    ['type' => 'conditional', 'condition' => '{{ statement.period_type }} == comparison', 'children' => [
                        ['type' => 'spacer', 'height' => 10],
                        ['type' => 'text', 'content' => '<b style="font-size:13px;color:#1a56db;">Outstanding Balance Summary</b>'],
                        ['type' => 'table', 'source' => 'comparison_rows', 'paginate' => false, 'columns' => [
                            ['label' => 'As Of', 'field' => 'as_of_date', 'width' => '24%'],
                            ['label' => 'Invoices', 'field' => 'invoice_count', 'width' => '12%', 'align' => 'right'],
                            ['label' => 'Total Outstanding', 'field' => 'total_outstanding', 'width' => '22%', 'align' => 'right', 'format' => 'money'],
                            ['label' => 'Change', 'field' => 'outstanding_change', 'width' => '21%', 'align' => 'right', 'format' => 'money'],
                            ['label' => 'Change (%)', 'field' => 'outstanding_change_pct', 'width' => '21%', 'align' => 'right'],
                        ]],
                        ['type' => 'spacer', 'height' => 12],
                        ['type' => 'text', 'content' => '<b style="font-size:13px;color:#1a56db;">Aging Breakdown</b>'],
                        ['type' => 'table', 'source' => 'comparison_rows', 'paginate' => false, 'columns' => [
                            ['label' => 'As Of', 'field' => 'as_of_date', 'width' => '28%'],
                            ['label' => 'Current', 'field' => 'aging_current', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                            ['label' => '1-30', 'field' => 'aging_1_30', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                            ['label' => '31-60', 'field' => 'aging_31_60', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                            ['label' => '61-90', 'field' => 'aging_61_90', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                            ['label' => '90+', 'field' => 'aging_90_plus', 'width' => '16%', 'align' => 'right', 'format' => 'money'],
                        ]],
                    ]],
                    // The canonical statement body is skipped for the
                    // comparison payload variant.
                    ['type' => 'totals', 'condition' => '{{ statement.period_type }} != comparison', 'rows' => [
                        ['label' => 'Opening Balance', 'field' => 'totals.opening_balance', 'format' => 'money'],
                        ['label' => 'Total Debits', 'field' => 'totals.total_debits', 'format' => 'money'],
                        ['label' => 'Total Credits', 'field' => 'totals.total_credits', 'format' => 'money'],
                        ['label' => 'Closing Balance', 'field' => 'totals.closing_balance', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'table', 'source' => 'transactions', 'condition' => '{{ statement.period_type }} != comparison', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '5%', 'align' => 'center'],
                        ['label' => 'Date', 'field' => 'date', 'width' => '12%'],
                        ['label' => 'Type', 'field' => 'type', 'width' => '13%'],
                        ['label' => 'Document #', 'field' => 'document_number', 'width' => '15%'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '25%'],
                        ['label' => 'Debit', 'field' => 'debit', 'width' => '10%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Credit', 'field' => 'credit', 'width' => '10%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Balance', 'field' => 'balance', 'width' => '10%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Issue #615 (TP5) — sales order family starter. Order confirmation and
     * pro forma invoice share this layout through thin data-source variants;
     * only the document heading and registered type differ.
     *
     * @return array<string,mixed>
     */
    private static function salesDocument(string $documentType, string $code, string $name, string $title): array
    {
        return [
            'document_type' => $documentType,
            'code' => $code,
            'name' => $name,
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'columns', 'gap' => 4, 'children' => [
                            ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'left'],
                            ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
                        ]],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">'.$title.'</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Bill To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ sales_order.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.tax_id }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Order #:</b> {{ sales_order.number }}<br/><b>Date:</b> {{ sales_order.order_date }}<br/><b>Required:</b> {{ sales_order.required_date }}<br/><b>PO No:</b> {{ sales_order.po_number }}<br/><b>Reference:</b> {{ sales_order.customer_reference }}'],
                    ]],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Ship To</b><br/><span style="font-size:10px;color:#374151;">{{ sales_order.shipping_address }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Attention:</b> {{ sales_order.attention_to }}<br/><b>Warehouse:</b> {{ sales_order.warehouse }}'],
                    ]],
                    // Issue #333: Incoterm (free-text code + place stored on
                    // the document) prints when set; hidden when unset.
                    ['type' => 'conditional', 'condition' => "{{ sales_order.incoterm_code }} != ''", 'children' => [
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Incoterm:</b> {{ sales_order.incoterm_code }} &mdash; {{ sales_order.incoterm_place }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'paginate' => false, 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '5%', 'align' => 'center'],
                        ['label' => 'SKU', 'field' => 'sku', 'width' => '12%'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '37%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '10%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '17%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '19%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                        ['label' => 'Discount', 'field' => 'totals.discount', 'format' => 'money'],
                        ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                        ['label' => 'Total', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'conditional', 'condition' => "{{ totals.amount_in_words }} != ''", 'children' => [
                        ['type' => 'text', 'size' => 9, 'color' => '#374151', 'content' => '<b>Amount in Words:</b> {{ totals.amount_in_words }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ sales_order.notes }}'],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }} · {{ company.website }}'],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function salesOrder(): array
    {
        return self::salesDocument('sales_order', 'SO-TPL-DEFAULT', 'Default Sales Order', 'SALES ORDER');
    }

    /** @return array<string,mixed> */
    public static function orderConfirmation(): array
    {
        return self::salesDocument('sales_order_confirmation', 'SOC-TPL-DEFAULT', 'Default Order Confirmation', 'ORDER CONFIRMATION');
    }

    /** @return array<string,mixed> */
    public static function proformaInvoice(): array
    {
        return self::salesDocument('sales_proforma_invoice', 'PRO-TPL-DEFAULT', 'Default Pro Forma Invoice', 'PRO FORMA INVOICE');
    }

    /**
     * Issue #615 (TP5) — customer credit note starter (#531). Statutory facts
     * (original invoice, reason, credited amount) come from the
     * CreditNoteDataSource; the runtime template type is `credit_note` while
     * the ADR-004 registration is `ar_credit_note`.
     *
     * @return array<string,mixed>
     */
    public static function creditNote(): array
    {
        return [
            'document_type' => 'credit_note',
            'code' => 'CN-TPL-DEFAULT',
            'name' => 'Default Credit Note',
            'mode' => 'block',
            'format' => 'A4',
            'orientation' => 'portrait',
            'default_font' => 'DejaVu Sans',
            'status' => 'published',
            'is_system_default' => true,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'columns', 'gap' => 4, 'children' => [
                            ['type' => 'image', 'url' => '{{ company.logo_url }}', 'width' => 120, 'align' => 'left'],
                            ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div><div style="font-size:10px;color:#6b7280;">{{ company.website }}</div>'],
                        ]],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">CREDIT NOTE</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Credit To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ credit_note.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.tax_id }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Credit Note #:</b> {{ credit_note.number }}<br/><b>Date:</b> {{ credit_note.issue_date }}<br/><b>Original Invoice:</b> {{ credit_note.invoice_number }}<br/><b>Invoice Date:</b> {{ credit_note.invoice_date }}<br/><b>Reason:</b> {{ credit_note.reason }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'paginate' => false, 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'SKU', 'field' => 'sku', 'width' => '12%'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '36%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '10%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '17%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '19%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                        ['label' => 'Discount', 'field' => 'totals.discount', 'format' => 'money'],
                        ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                        ['label' => 'Credit Amount', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'conditional', 'condition' => "{{ totals.amount_in_words }} != ''", 'children' => [
                        ['type' => 'text', 'size' => 9, 'color' => '#374151', 'content' => '<b>Amount in Words:</b> {{ totals.amount_in_words }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ credit_note.notes }}'],
                    ['type' => 'spacer', 'height' => 8],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }} · {{ company.website }}'],
                    ]],
                ],
            ],
        ];
    }
}
