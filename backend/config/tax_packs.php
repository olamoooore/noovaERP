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
| 6→8, plus historical rows so amended filings remain reproducible)
| configuration, not code changes.
|
| #734 T1: the 2026 "SST (GST-model)" 8%/10% rows were removed — no such
| regime was enacted (decision reversal recorded on #269). The MY service
| timeline itself is under correction per #735 pending RMCD-verified data;
| do not add 2026+ MY rates without a tax-agent-confirmed statutory source.
*/

return [
    'SG' => [
        'label' => 'Singapore GST (IRAS)',
        'currency' => 'SGD',
        // #741 T8: period shapes this pack can be filed under.
        'period_shapes' => ['monthly', 'quarterly'],
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
        // #741 T8: SST-02 taxable periods are two months, aligned to each
        // registrant's cycle start month (tax_registrations.filing_start_month).
        // 'monthly' stays accepted for existing single-month reporting; a
        // quarterly SST-02 is not a real RMCD period and is rejected.
        'period_shapes' => ['bimonthly', 'monthly'],
        // #738 T5: MY classifies lines as service vs sales; SG has a single
        // GST type and must not be filtered by a MY-specific 'service' type.
        'line_type_map' => ['service' => 'service'],
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
                'creditable' => false,
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
                'creditable' => false,
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
                'creditable' => false,
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
                'creditable' => false,
            ],
        ],
    ],
];
