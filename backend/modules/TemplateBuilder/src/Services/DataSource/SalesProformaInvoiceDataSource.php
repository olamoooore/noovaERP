<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

/**
 * Issue #615 (TP5) — Pro Forma Invoice is a thin variant of the sales order
 * source: the same order record, rendered under its own registered type key
 * (`sales_proforma_invoice`) and template.
 */
final class SalesProformaInvoiceDataSource extends SalesOrderDataSource
{
    public static function documentType(): string
    {
        return 'sales_proforma_invoice';
    }
}
