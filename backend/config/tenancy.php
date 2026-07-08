<?php

declare(strict_types=1);

use Stancl\Tenancy\Database\Models\Domain;

return [
    'tenant_model' => \App\Models\Tenant::class,
    'id_generator' => Stancl\Tenancy\UUIDGenerator::class,

    'domain_model' => Domain::class,

    'central_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('CENTRAL_DOMAINS', 'localhost'))))),

    // ── Dedicated storefront tenant ─────────────────────────────────────────
    // When public ecommerce / website-public routes are hit without a domain
    // or subdomain that maps to a specific tenant, this tenant is used.
    //
    // Prefer STOREFRONT_TENANT_ID (UUID) over STOREFRONT_TENANT_EMAIL.
    // Leave both null to rely on domain/subdomain resolution only.
    'storefront_tenant_id'    => env('STOREFRONT_TENANT_ID'),
    'storefront_tenant_email' => env('STOREFRONT_TENANT_EMAIL'),

    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => env('DB_CONNECTION', 'pgsql'),
        
        'template_tenant_connection' => null,

        'prefix' => 'tenant',
        'suffix' => '',

        'managers' => [
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
        ],
    ],

    'redis' => [
        'prefixed_connections' => [
            'default',
        ],
    ],

    'filesystem' => [
        'suffix_base' => env('TENANCY_FILESYSTEM_SUFFIX_BASE', 'tenant'),
        'disks' => [
            'local',
            'public',
            // 's3',
        ],

        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
    ],

    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\TenantConfig::class,
        // Stancl\Tenancy\Features\CrossDomainRedirect::class,
    ],

    'migration_parameters' => [
        '--force' => true,
        '--path' => [
            database_path('migrations/tenant'),
            base_path('modules/EventManagement/Database/Migrations/tenant'),
            base_path('modules/FinancialReporting/database/migrations/tenant'),
            base_path('modules/HRM/database/migrations/tenant'),
            base_path('modules/OCRBookkeeping/database/migrations/tenant'),
            base_path('modules/POS/database/migrations/tenant'),
            base_path('modules/Ecommerce/database/migrations/tenant'),
            base_path('modules/AI/database/migrations/tenant'),
            base_path('modules/FieldService/database/migrations/tenant'),
            base_path('modules/WebsiteBuilder/database/migrations/tenant'),
            base_path('modules/IoT/database/migrations/tenant'),
            base_path('modules/Loyalty/database/migrations/tenant'),
            base_path('modules/TemplateBuilder/database/migrations/tenant'),
        ],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];
