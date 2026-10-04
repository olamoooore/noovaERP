<?php

declare(strict_types=1);

return [
    'defaults' => [
        'paper_size' => env('TB_DEFAULT_PAPER', 'A4'),
        'orientation' => 'portrait',
        'font' => env('TB_DEFAULT_FONT', 'DejaVu Sans'),
        'margin_mm' => ['top' => 15, 'right' => 12, 'bottom' => 15, 'left' => 12],
    ],

    'pdf' => [
        'engine' => 'dompdf',
        'render_timeout' => (int) env('TB_PDF_TIMEOUT', 30),
        'cache_ttl_min' => (int) env('TB_CACHE_TTL', 1440),
        'batch_chunk' => 50,
        // Issue #613: disk holding immutable copies of issued documents.
        // Registered via `Storage::disk(...)`; production points it at the
        // tenant's object storage.
        'snapshot_disk' => env('TB_SNAPSHOT_DISK', 'local'),
    ],

    'assets' => [
        'disk' => env('TB_ASSET_DISK', 'public'),
        'max_upload_kb' => 4096,
        'allowed' => ['png', 'jpg', 'jpeg', 'svg', 'woff', 'woff2'],
    ],

    'code_mode' => [
        'enabled' => (bool) env('TB_CODE_MODE', true),
        'require_permission' => 'templates.code_mode',
    ],
];
