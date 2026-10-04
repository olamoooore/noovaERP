<?php

/*
|--------------------------------------------------------------------------
| Customer import profiles
|--------------------------------------------------------------------------
|
| Issue #433 (CU5): the staged customer importer is a generic shared service.
| Source systems and column mappings that are specific to one client belong
| here, as a profile — never as a class name or hardcoded default value in
| shared service code.
|
| Each profile declares:
|   source_system — the approved lineage value recorded on the import batch.
|   mapping       — canonical field => source column, or null to use the
|                   importer's built-in generic column resolution.
|
| Deployments select a profile with CUSTOMER_IMPORT_PROFILE.
|
*/

return [
    'default_profile' => env('CUSTOMER_IMPORT_PROFILE', 'generic'),

    'profiles' => [
        'generic' => [
            'source_system' => 'customer_import',
            'mapping' => null,
        ],

        // Legacy Acme customer-master feed: the client-specific source
        // system lives here as configuration, not as a service class name.
        'acme' => [
            'source_system' => 'acme_customer_master',
            'mapping' => null,
        ],
    ],
];
