<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the external OCR service (Python FastAPI)
    |
    */

    'service' => [
        'url' => env('OCR_SERVICE_URL', 'http://localhost:8000'),
        // Issue #174: shared secret for the internal OCR microservice. Must
        // match the service's OCR_SERVICE_KEY; sent as X-OCR-Service-Key.
        'key' => env('OCR_SERVICE_KEY'),
        'timeout' => env('OCR_SERVICE_TIMEOUT', 60),
        'max_retries' => env('OCR_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OCR processing queues
    |
    */

    'queue' => [
        'connection' => env('OCR_QUEUE_CONNECTION', 'redis'),
        'name' => env('OCR_QUEUE_NAME', 'ocr-processing'),
        'workers' => env('OCR_QUEUE_WORKERS', 2),
        'timeout' => env('OCR_QUEUE_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | BI Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Business Intelligence update queues
    |
    */

    'bi_queue' => [
        'name' => env('BI_QUEUE_NAME', 'bi-updates'),
        'workers' => env('BI_QUEUE_WORKERS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Currency Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for currency conversion and exchange rates
    |
    */

    'currency' => [
        'base' => env('BASE_CURRENCY', 'USD'),
        'api_url' => env('EXCHANGE_RATE_API_URL', 'https://api.exchangerate-api.com/v4/latest/'),
        'cache_minutes' => env('EXCHANGE_RATE_CACHE_MINUTES', 1440), // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific OCR features
    |
    */

    'features' => [
        'auto_post_high_confidence' => env('OCR_AUTO_POST_HIGH_CONFIDENCE', false),
        'min_confidence_threshold' => env('OCR_MIN_CONFIDENCE_THRESHOLD', 0.7),
        'enable_batch_notifications' => env('OCR_ENABLE_BATCH_NOTIFICATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for file uploads
    |
    */

    'upload' => [
        'max_file_size' => 10240, // 10MB in KB
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'pdf'],
        'max_batch_size' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for document storage
    |
    */

    'storage' => [
        'disk' => env('OCR_STORAGE_DISK', 'ocr-documents-local'),
        'path_prefix' => 'ocr-documents',
    ],

];
