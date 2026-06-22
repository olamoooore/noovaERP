<?php

return [

    /*
    |--------------------------------------------------------------------------
    | XBRL Compliance Feature Flag
    |--------------------------------------------------------------------------
    | Set to true to enable XBRL statutory report generation.
    | This is an optional feature and does NOT affect core accounting.
    */
    'enabled' => (bool) env('COMPLIANCE_ENABLED', false),

    'singapore' => [
        'taxonomy' => [
            'enabled' => (bool) env('COMPLIANCE_SG_TAXONOMY_VALIDATION_ENABLED', true),

            'strict' => (bool) env('COMPLIANCE_SG_TAXONOMY_STRICT', false),

            'package_path' => env(
                'COMPLIANCE_SG_TAXONOMY_PACKAGE_PATH',
                base_path('modules/AccountingCountry/Singapore/Taxonomy/ACRA-SFRS-2024/ACRA Taxonomy 2026 v1.0')
            ),

            'entry_point_xsd' => env('COMPLIANCE_SG_TAXONOMY_ENTRY_XSD', 'sg-sfrs-ent_2026-01-01.xsd'),

            'schema_ref_href' => env('COMPLIANCE_SG_TAXONOMY_SCHEMA_HREF', 'sg-sfrs-ent_2026-01-01.xsd'),
        ],
    ],
];
