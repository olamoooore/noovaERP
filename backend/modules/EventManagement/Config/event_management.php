<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Event Management Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the ERP Event Management Module
    |
    */

    'feature_flags' => [
        'event_management' => [
            'enabled' => env('EVENT_MANAGEMENT_ENABLED', false),
            'description' => 'Enable event management functionality for tenants',
        ],
    ],

    'defaults' => [
        'currency' => env('EVENT_MANAGEMENT_DEFAULT_CURRENCY', 'USD'),
        'timezone' => env('EVENT_MANAGEMENT_DEFAULT_TIMEZONE', 'UTC'),
        'max_capacity_per_event' => env('EVENT_MANAGEMENT_MAX_CAPACITY', 10000),
        'data_retention_days' => env('EVENT_MANAGEMENT_RETENTION_DAYS', 2555), // 7 years
    ],

    'booking' => [
        'states' => [
            'new' => 'new',
            'pending' => 'pending',
            'confirmed' => 'confirmed',
            'booked' => 'booked',
            'cancelled' => 'cancelled',
        ],
        'valid_transitions' => [
            'new' => ['pending', 'cancelled'],
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['booked', 'cancelled'],
            'booked' => ['cancelled'],
            'cancelled' => [], // Terminal state
        ],
    ],

    'api' => [
        'rate_limits' => [
            'public_booking' => env('EVENT_MANAGEMENT_RATE_LIMIT', '60,1'), // 60 requests per minute
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for encrypting sensitive personal data like ID information
    |
    */
    'encryption' => [
        'enabled' => env('EVENT_MANAGEMENT_ENCRYPTION_ENABLED', true),
        'algorithm' => 'AES-256-CBC',
        'max_data_length' => 65535, // 64KB limit
        'min_id_length' => 6,
        'id_validation_patterns' => [
            'passport' => '/^[A-Z]{2,4}[0-9]{6,}[A-Z]?$/',
            'numeric' => '/^[0-9]{6,12}$/',
            'nric' => '/^[A-Z][0-9]{7,}[A-Z]$/',
            'general' => '/^[A-Z0-9]{8,}$/',
        ],
        'integrity_check' => env('EVENT_MANAGEMENT_ENCRYPTION_INTEGRITY_CHECK', true),
        'secure_logging' => env('EVENT_MANAGEMENT_ENCRYPTION_SECURE_LOGGING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal Data Protection Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for PDPA compliance and data protection
    |
    */
    'data_protection' => [
        'require_pdpa_consent' => env('EVENT_MANAGEMENT_REQUIRE_PDPA_CONSENT', true),
        'encrypt_id_fields' => env('EVENT_MANAGEMENT_ENCRYPT_ID_FIELDS', true),
        'log_data_access' => env('EVENT_MANAGEMENT_LOG_DATA_ACCESS', true),
        'data_retention_enforcement' => env('EVENT_MANAGEMENT_DATA_RETENTION_ENFORCEMENT', true),
    ],
];