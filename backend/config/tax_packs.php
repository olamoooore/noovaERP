<?php

/*
|--------------------------------------------------------------------------
| Tax country packs (MRD TAX-REQ-05 / TAX-REQ-06, R2.4, R5.1, R6.1)
|--------------------------------------------------------------------------
|
| Seeded as tenant-configurable rule DATA — never hardcoded taxation. The
| engine only ever charges what the tenant activates; rates here are a
| reviewable starting point and MUST be verified against IRAS / LHDN-RMCD
| notices before a period goes live (compliance deliverable, MRD §12).
|
| Every row maps to one `taxes` record, matched idempotently by `code`.
| Effective-dated rows are what make regime changes (SG 7→8→9, MY service
| 6→8, MY 2026 SST-GST 8% from 2026-01 and planned 10% from 2026-07, plus
| historical rows so amended filings remain reproducible) configuration,
| not code changes.
*/

return [
    'SG' => [
        'label' => 'Singapore GST (IRAS)',
        'currency' => 'SGD',
        'rules' => [
            [
                'code' => 'SG-GST-9',
                'name' => 'GST 9% — verify current IRAS guidance',
                'type' => 'gst',
                'regime' => 'GST',
                'jurisdiction' => 'SG',
                'scope' => 'both',
                'treatment' => 'standard_rated',
                'rate' => 9.00,
                'is_inclusive' => false,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'SG-GST-8',
                'name' => 'GST 8% (historical 2023 — amendments only)',
                'type' => 'gst',
                'regime' => 'GST',
                'jurisdiction' => 'SG',
                'scope' => 'both',
                'treatment' => 'standard_rated',
                'rate' => 8.00,
                'is_inclusive' => false,
                'effective_from' => '2023-01-01',
                'effective_to' => '2023-12-31',
            ],
            [
                'code' => 'SG-GST-7',
                'name' => 'GST 7% (historical ≤2022 — amendments only)',
                'type' => 'gst',
                'regime' => 'GST',
                'jurisdiction' => 'SG',
                'scope' => 'both',
                'treatment' => 'standard_rated',
                'rate' => 7.00,
                'is_inclusive' => false,
                'effective_from' => '2020-01-01',
                'effective_to' => '2022-12-31',
            ],
            [
                'code' => 'SG-GST-ZERO',
                'name' => 'Zero-rated supplies (exports, international services)',
                'type' => 'gst',
                'regime' => 'GST',
                'jurisdiction' => 'SG',
                'scope' => 'output',
                'treatment' => 'zero_rated',
                'rate' => 0.00,
                'is_inclusive' => false,
                'effective_from' => '2020-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'SG-GST-EXEMPT',
                'name' => 'Exempt supplies (financial services, IPM, residential)',
                'type' => 'gst',
                'regime' => 'GST',
                'jurisdiction' => 'SG',
                'scope' => 'both',
                'treatment' => 'exempt',
                'rate' => 0.00,
                'is_inclusive' => false,
                'effective_from' => '2020-01-01',
                'effective_to' => null,
            ],
        ],
    ],

    'MY' => [
        'label' => 'Malaysia SST (LHDN / RMCD)',
        'currency' => 'MYR',
        'rules' => [
            [
                'code' => 'MY-SALES-10',
                'name' => 'Sales tax 10% (scheduled goods, single-stage, non-creditable)',
                'type' => 'sales',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'output',
                'treatment' => 'standard_rated',
                'rate' => 10.00,
                'is_inclusive' => false,
                'effective_from' => '2018-09-01',
                'effective_to' => null,
            ],
            [
                'code' => 'MY-SALES-5',
                'name' => 'Sales tax 5% (reduced goods schedule)',
                'type' => 'sales',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'output',
                'treatment' => 'standard_rated',
                'rate' => 5.00,
                'is_inclusive' => false,
                'effective_from' => '2018-09-01',
                'effective_to' => null,
            ],
            [
                'code' => 'MY-SVC-6',
                'name' => 'Service tax 6% (historical to 2025-06-30 — amendments only)',
                'type' => 'service',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'output',
                'treatment' => 'standard_rated',
                'rate' => 6.00,
                'is_inclusive' => false,
                'effective_from' => '2018-09-01',
                'effective_to' => '2025-06-30',
            ],
            [
                'code' => 'MY-SVC-8',
                'name' => 'Service tax 8% — verify scope/group with JKDM',
                'type' => 'service',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'output',
                'treatment' => 'standard_rated',
                'rate' => 8.00,
                'is_inclusive' => false,
                'effective_from' => '2025-07-01',
                'effective_to' => '2025-12-31',
            ],
            [
                // Budget-2025-announced SST modelled on GST with input credit.
                // Rate path (8% from 1 Jan 2026, planned 10% from 1 Jul 2026
                // after review) MUST be confirmed against the enacted
                // instruments before this pack is used for filing.
                'code' => 'MY-SSTG-8',
                'name' => 'SST (GST-model) 8% — VERIFY 2026 instruments before filing',
                'type' => 'gst',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'both',
                'treatment' => 'standard_rated',
                'rate' => 8.00,
                'is_inclusive' => false,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-06-30',
            ],
            [
                'code' => 'MY-SSTG-10',
                'name' => 'SST (GST-model) 10% planned — VERIFY enactment/date before filing',
                'type' => 'gst',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'both',
                'treatment' => 'standard_rated',
                'rate' => 10.00,
                'is_inclusive' => false,
                'effective_from' => '2026-07-01',
                'effective_to' => null,
            ],
            [
                'code' => 'MY-SST-ZERO',
                'name' => 'Zero-rated supplies (2026 regime essential basket — verify list)',
                'type' => 'gst',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'output',
                'treatment' => 'zero_rated',
                'rate' => 0.00,
                'is_inclusive' => false,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'MY-SST-EXEMPT',
                'name' => 'Exempt supplies (2026 regime — verify schedule)',
                'type' => 'gst',
                'regime' => 'SST',
                'jurisdiction' => 'MY',
                'scope' => 'both',
                'treatment' => 'exempt',
                'rate' => 0.00,
                'is_inclusive' => false,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
        ],
    ],
];
