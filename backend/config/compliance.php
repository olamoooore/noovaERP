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

    'malaysia' => [
        'myinvoice_test_mode'    => (bool) env('COMPLIANCE_MY_MYINVOICE_TEST_MODE', true),
        'myinvoice_identity_service' => env('COMPLIANCE_MY_MYINVOICE_IDENTITY_SERVICE', ''),
        'myinvoice_client_id'    => env('COMPLIANCE_MY_MYINVOICE_CLIENT_ID', ''),
        'myinvoice_client_secret'=> env('COMPLIANCE_MY_MYINVOICE_CLIENT_SECRET', ''),
        'myinvoice_scope'        => env('COMPLIANCE_MY_MYINVOICE_SCOPE', 'InvoicingAPI'),
        'myinvoice_api_endpoint' => env('COMPLIANCE_MY_MYINVOICE_API_ENDPOINT', ''),

        'supplier_tin'           => env('COMPLIANCE_MY_SUPPLIER_TIN', ''),
        'supplier_name'          => env('COMPLIANCE_MY_SUPPLIER_NAME', ''),
        'supplier_id_type'       => env('COMPLIANCE_MY_SUPPLIER_ID_TYPE', 'BRN'),
        'supplier_id_number'     => env('COMPLIANCE_MY_SUPPLIER_ID_NUMBER', ''),
        'supplier_is_individual' => (bool) env('COMPLIANCE_MY_SUPPLIER_IS_INDIVIDUAL', false),
        'supplier_msic_code'     => env('COMPLIANCE_MY_SUPPLIER_MSIC_CODE', '00000'),
        'supplier_business_activity_description' => env('COMPLIANCE_MY_SUPPLIER_BUSINESS_ACTIVITY_DESCRIPTION', 'General Trading'),
        'supplier_phone'         => env('COMPLIANCE_MY_SUPPLIER_PHONE', '+60000000000'),
        'supplier_email'         => env('COMPLIANCE_MY_SUPPLIER_EMAIL', 'no-reply@example.com'),
        'supplier_address'       => env('COMPLIANCE_MY_SUPPLIER_ADDRESS', ''),
        'supplier_city'          => env('COMPLIANCE_MY_SUPPLIER_CITY', ''),
        'supplier_state'         => env('COMPLIANCE_MY_SUPPLIER_STATE', ''),
        'supplier_postal_code'   => env('COMPLIANCE_MY_SUPPLIER_POSTAL_CODE', ''),

        'internal_supplier_name' => env('COMPLIANCE_MY_INTERNAL_SUPPLIER_NAME', 'General Employee'),
        'internal_supplier_tin'  => env('COMPLIANCE_MY_INTERNAL_SUPPLIER_TIN', 'EI00000000010'),
        'internal_supplier_nric' => env('COMPLIANCE_MY_INTERNAL_SUPPLIER_NRIC', '000000000000'),

        'default_buyer_tin'      => env('COMPLIANCE_MY_DEFAULT_BUYER_TIN', 'EI00000000010'),
        'default_buyer_phone'    => env('COMPLIANCE_MY_DEFAULT_BUYER_PHONE', '+60000000000'),
        'default_buyer_email'    => env('COMPLIANCE_MY_DEFAULT_BUYER_EMAIL', ''),

        'signature_enabled'               => (bool) env('COMPLIANCE_MY_SIGNATURE_ENABLED', false),
        'signature_use_for_submission'    => (bool) env('COMPLIANCE_MY_SIGNATURE_USE_FOR_SUBMISSION', false),
        'signature_key_id'                => env('COMPLIANCE_MY_SIGNATURE_KEY_ID', ''),
        'signature_name'                  => env('COMPLIANCE_MY_SIGNATURE_NAME', ''),
        'signature_private_key'           => env('COMPLIANCE_MY_SIGNATURE_PRIVATE_KEY', ''),
        'signature_private_key_passphrase'=> env('COMPLIANCE_MY_SIGNATURE_PRIVATE_KEY_PASSPHRASE', ''),
        'signature_certificate'           => env('COMPLIANCE_MY_SIGNATURE_CERTIFICATE', ''),
    ],

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
