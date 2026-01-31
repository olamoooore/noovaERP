<?php

return [
    'name' => 'FinancialReporting',

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('FINANCIAL_REPORTING_CACHE_ENABLED', true),
        'ttl' => env('FINANCIAL_REPORTING_CACHE_TTL', 3600), // 1 hour
        'prefix' => 'financial_report:',
        'tags' => [
            'financial_reports',
            'inventory_reports',
            'analytics',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Configuration
    |--------------------------------------------------------------------------
    */
    'reports' => [
        // Use daily_ledger_summaries for data older than X days
        'use_summary_after_days' => env('FINANCIAL_REPORTING_SUMMARY_DAYS', 7),
        
        // Maximum date range for reports (in days)
        'max_date_range' => env('FINANCIAL_REPORTING_MAX_RANGE', 365),
        
        // Default pagination
        'per_page' => env('FINANCIAL_REPORTING_PER_PAGE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    */
    'export' => [
        'disk' => env('FINANCIAL_REPORTING_EXPORT_DISK', 'local'),
        'path' => 'exports/reports',
        'cleanup_after_hours' => env('FINANCIAL_REPORTING_EXPORT_CLEANUP', 24),
        
        'pdf' => [
            'enabled' => true,
            'orientation' => 'portrait', // portrait or landscape
            'paper_size' => 'a4',
        ],
        
        'excel' => [
            'enabled' => true,
            'format' => 'xlsx', // xlsx or csv
        ],
        
        'csv' => [
            'enabled' => true,
            'delimiter' => ',',
            'enclosure' => '"',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'refresh_interval' => env('FINANCIAL_REPORTING_DASHBOARD_REFRESH', 300), // 5 minutes
        'kpi_cache_ttl' => env('FINANCIAL_REPORTING_KPI_CACHE', 600), // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling Configuration
    |--------------------------------------------------------------------------
    */
    'scheduling' => [
        'enabled' => env('FINANCIAL_REPORTING_SCHEDULING_ENABLED', true),
        'max_schedules_per_user' => env('FINANCIAL_REPORTING_MAX_SCHEDULES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'chunk_size' => env('FINANCIAL_REPORTING_CHUNK_SIZE', 1000),
        'query_timeout' => env('FINANCIAL_REPORTING_QUERY_TIMEOUT', 30), // seconds
    ],
];
