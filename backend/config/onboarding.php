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
        'group_setup'        => ['label' => 'Group Setup',              'prerequisites' => []],
        'entity_selection'   => ['label' => 'Entity Selection',         'prerequisites' => ['group_setup']],
        'country_compliance' => ['label' => 'Country & Compliance',     'prerequisites' => ['entity_selection']],
        'coa_import'         => ['label' => 'Chart of Accounts Import', 'prerequisites' => ['country_compliance']],
        'role_assignment'    => ['label' => 'Account Role Assignment',  'prerequisites' => ['coa_import']],
        'validation'         => ['label' => 'Validation & Review',      'prerequisites' => ['role_assignment']],
        'go_live'            => ['label' => 'Go Live',                  'prerequisites' => ['validation']],
    ],
];
