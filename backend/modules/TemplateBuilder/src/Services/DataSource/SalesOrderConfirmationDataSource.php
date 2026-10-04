<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

/**
 * Issue #615 (TP5) — Order Confirmation is a thin variant of the sales order
 * source: the same order record, rendered under its own registered type key
 * (`sales_order_confirmation`) and template.
 */
final class SalesOrderConfirmationDataSource extends SalesOrderDataSource
{
    public static function documentType(): string
    {
        return 'sales_order_confirmation';
    }
}
