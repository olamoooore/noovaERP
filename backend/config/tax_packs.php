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
        // #739 T6: pack version. Bump whenever a rule below changes, so every
        // tenant row records which pack revision it came from and status()
        // can report drift instead of claiming a stale pack is "activated".
        'version' => 1,
        // #741 T8: period shapes this pack can be filed under.
        'period_shapes' => ['monthly', 'quarterly'],
        // #743 T10: registration thresholds as DATA (amount, currency, sector
        // category, backward/forward test, effective window, source). Values
        // are PENDING tax-agent confirmation (verified:false) — no release
        // should rely on them until that sign-off and an official citation.
        'thresholds' => [
            [
                'key' => 'expected_taxable_turnover',
                'label' => 'IRAS GST mandatory registration',
                'amount' => 1000000.00,
                'currency' => 'SGD',
                'category' => 'all',
                'test' => 'backward',
                'effective_from' => null,
                'effective_to' => null,
                'source' => 'IRAS — GST registration guidance ("Who must register for GST")',
                'verified' => false,
                'note' => 'Backward-looking trailing-twelve-month taxable supplies; forward figures are a separate input. Effective date not asserted — pending tax-agent confirmation.',
            ],
        ],
        // #745 T12: the full IRAS GST F5 box set as DATA. 'computed' boxes are
        // derived from posted tax/revenue data; 'manual' boxes need operator
        // input (recorded and audited at filing); 'declaration' boxes are
        // yes/no. Box numbering and wording are marked verified:false pending
        // confirmation against the current IRAS F5 form (verified per release).
        'f5_boxes' => [
            ['box' => 1, 'key' => 'box_1_standard_rated_supplies', 'label' => 'Standard-rated supplies', 'kind' => 'computed', 'source' => 'output_supplies:standard_rated', 'verified' => false],
            ['box' => 2, 'key' => 'box_2_zero_rated_supplies', 'label' => 'Zero-rated supplies', 'kind' => 'computed', 'source' => 'output_supplies:zero_rated', 'verified' => false],
            ['box' => 3, 'key' => 'box_3_exempt_supplies', 'label' => 'Exempt supplies', 'kind' => 'computed', 'source' => 'output_supplies:exempt', 'verified' => false],
            ['box' => 4, 'key' => 'box_4_total_supplies', 'label' => 'Total supplies (1)+(2)+(3)', 'kind' => 'computed', 'source' => 'sum:1,2,3', 'verified' => false],
            ['box' => 5, 'key' => 'box_5_taxable_purchases', 'label' => 'Taxable purchases', 'kind' => 'computed', 'source' => 'input_tax:taxable', 'verified' => false],
            ['box' => 6, 'key' => 'box_6_output_tax_due', 'label' => 'Output tax due', 'kind' => 'computed', 'source' => 'output_tax', 'verified' => false],
            ['box' => 7, 'key' => 'box_7_input_tax_and_refunds', 'label' => 'Input tax and refunds claimed', 'kind' => 'computed', 'source' => 'input_tax', 'verified' => false],
            ['box' => 8, 'key' => 'box_8_net_gst_payable', 'label' => 'Net GST payable', 'kind' => 'computed', 'source' => 'difference:6,7', 'verified' => false],
            ['box' => 9, 'key' => 'box_9_imports_under_schemes', 'label' => 'Imports under MES / 3PL / other approved schemes', 'kind' => 'manual', 'source' => 'manual', 'verified' => false],
            ['box' => 10, 'key' => 'box_10_tourist_refund_claimed', 'label' => 'Tourist refund claimed', 'kind' => 'manual', 'source' => 'manual', 'verified' => false],
            ['box' => 11, 'key' => 'box_11_bad_debt_relief', 'label' => 'Bad-debt relief claims and reverse-charge refund claims', 'kind' => 'manual', 'source' => 'manual', 'verified' => false],
            ['box' => 12, 'key' => 'box_12_pre_registration_claims', 'label' => 'Pre-registration claims', 'kind' => 'manual', 'source' => 'manual', 'verified' => false],
            ['box' => 13, 'key' => 'box_13_revenue', 'label' => 'Revenue for the accounting period', 'kind' => 'computed', 'source' => 'revenue', 'verified' => false],
            ['box' => 14, 'key' => 'box_14_imported_services_reverse_charge', 'label' => 'Imported services / LVG subject to reverse charge', 'kind' => 'manual', 'source' => 'manual', 'verified' => false],
            ['box' => 15, 'key' => 'box_15_electronic_marketplace', 'label' => 'Made electronic marketplace / redeliverer supplies?', 'kind' => 'declaration', 'source' => 'manual', 'verified' => false],
        ],
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
        'version' => 1,
        // #741 T8: SST-02 taxable periods are two months, aligned to each
        // registrant's cycle start month (tax_registrations.filing_start_month).
        // 'monthly' stays accepted for existing single-month reporting; a
        // quarterly SST-02 is not a real RMCD period and is rejected.
        'period_shapes' => ['bimonthly', 'monthly'],
        // #743 T10: thresholds keyed by service-tax category. Sector keys
        // (leasing/financial, construction, …) land with the #735 taxonomy;
        // values and effective dates are PENDING RMCD confirmation and
        // tax-agent sign-off (verified:false). The unsourced "RM1.975m
        // catering & accommodation" note in the old constant was removed.
        'thresholds' => [
            [
                'key' => 'taxable_services',
                'label' => 'RMCD service tax registration',
                'amount' => 500000.00,
                'currency' => 'MYR',
                'category' => 'services',
                'test' => 'backward',
                'effective_from' => null,
                'effective_to' => null,
                'source' => 'RMCD — Service Tax registration threshold guidance',
                'verified' => false,
                'note' => 'Sector categories pending #735 taxonomy and RMCD confirmation.',
            ],
            [
                'key' => 'taxable_goods',
                'label' => 'RMCD sales tax registration (manufacturers)',
                'amount' => 500000.00,
                'currency' => 'MYR',
                'category' => 'goods',
                'test' => 'backward',
                'effective_from' => null,
                'effective_to' => null,
                'source' => 'RMCD — Sales Tax registration threshold guidance',
                'verified' => false,
                'note' => 'Value of taxable goods manufactured in 12 months. Pending tax-agent confirmation.',
            ],
        ],
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
