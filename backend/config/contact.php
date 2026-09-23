<?php

/*
|--------------------------------------------------------------------------
| Contact module configuration
|--------------------------------------------------------------------------
|
| Issue #123: the healthcare vocabulary (DOCTOR/NURSE/CLINICAL/OT_CONTACT)
| used to be seeded into EVERY tenant's taxonomy tables. It is now gated: only
| tenants listed here receive the Acme healthcare vocabulary; every other
| tenant gets the generic business vocabulary. Tenants remain extensible at
| runtime by seeding their own contact_taxonomy / contact_groups rows.
|
*/

return [
    'healthcare_tenant_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CONTACT_HEALTHCARE_TENANT_IDS',
            ''
        ))
    ))),
];
