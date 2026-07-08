<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

/**
 * Quotations are modelled in NoovaERP as draft invoices. This source reuses the
 * Invoice payload but reports a distinct document type so templates can use
 * Quotation wording/layouts.
 */
final class QuotationDataSource extends InvoiceDataSource
{
    public static function documentType(): string
    {
        return 'quotation';
    }

    public function sample(): array
    {
        $base = parent::sample();
        $base['invoice']['number'] = 'QT-2026-0001';
        $base['invoice']['status'] = 'draft';

        return $base;
    }
}
