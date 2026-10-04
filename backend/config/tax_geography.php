<?php

/*
|--------------------------------------------------------------------------
| Tax geography (MRD TAX-REQ-05, #744 T11)
|--------------------------------------------------------------------------
|
| Country → jurisdiction and country → regime NAMES. These are geography and
| labelling, not tax rates: rates, currency and the regime that actually
| charges come from the country packs (config/tax_packs.php) through
| App\Services\TaxPackRegistry. Kept out of service classes so no code change
| is needed to add a country, and no service constant can drift from the pack.
|
*/

return [
    'country_jurisdictions' => [
        'SG' => 'SG',
        'MY' => 'MY',
        'AU' => 'AU',
        'NZ' => 'NZ',
        'GB' => 'GB',
        'US' => 'US',
        'ID' => 'ID',
        'TH' => 'TH',
        'PH' => 'PH',
        'VN' => 'VN',
        'IN' => 'IN',
    ],

    'country_regimes' => [
        'SG' => 'GST',
        'MY' => 'SST',
        'AU' => 'GST',
        'NZ' => 'GST',
        'GB' => 'VAT',
        'US' => 'SALES_TAX',
        'ID' => 'VAT',
        'TH' => 'VAT',
        'PH' => 'VAT',
        'VN' => 'VAT',
        'IN' => 'GST',
    ],

    /*
    | #614 (TP4): generic statutory invoice field labels. Jurisdiction packs
    | (config/tax_packs.php 'invoice_fields') override these; a jurisdiction
    | with no pack keeps the generic wording. Labels/requiredness only — no
    | rates or thresholds live here.
    */
    'document_field_labels' => [
        'tin' => ['label' => 'Tax Identification No.', 'required' => false],
        'sst_number' => ['label' => 'Sales/Service Tax Registration No.', 'required' => false],
        'gst_number' => ['label' => 'GST Registration No.', 'required' => false],
        'bank' => ['label' => 'Bank Details', 'required' => false],
    ],
];
