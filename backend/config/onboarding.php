<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Onboarding Steps
    |--------------------------------------------------------------------------
    |
    | Ordered step definitions: code => [label, prerequisites].
    | Prerequisites are step_codes that must be 'completed' before that step.
    |
    */
    'steps' => [
        'group_setup' => [
            'label' => 'Group Setup',
            'description' => 'Create your organizational group — the top-level entity that owns all legal entities, branches, and books.',
            'prerequisites' => [],
        ],
        'entity_selection' => [
            'label' => 'Entity Selection',
            'description' => 'Add the legal entities (companies) that operate under your group and assign them to the correct jurisdictions.',
            'prerequisites' => ['group_setup'],
        ],
        'country_compliance' => [
            'label' => 'Country & Compliance',
            'description' => 'Configure tax regimes, fiscal year settings, and regulatory compliance rules for each entity.',
            'prerequisites' => ['entity_selection'],
        ],
        'tax_registration' => [
            'label' => 'Tax Registration',
            'description' => 'Record each entity\'s tax registration (GST/SST number, filing frequency) or confirm it is not yet registered.',
            'prerequisites' => ['country_compliance'],
        ],
        'coa_import' => [
            'label' => 'Chart of Accounts',
            'description' => 'Import or create your chart of accounts — the backbone of your general ledger.',
            'prerequisites' => ['country_compliance'],
        ],
        'role_assignment' => [
            'label' => 'Account Roles',
            'description' => 'Map key accounts to system roles such as default receivables, payables, bank, and retained earnings.',
            'prerequisites' => ['coa_import'],
        ],
        'validation' => [
            'label' => 'Review & Validate',
            'description' => 'Review your full configuration, run validation checks, and fix any issues before going live.',
            'prerequisites' => ['role_assignment'],
        ],
        'go_live' => [
            'label' => 'Go Live',
            'description' => 'Finalize setup, lock the opening period, and activate your accounting system.',
            'prerequisites' => ['validation'],
        ],
    ],
];
