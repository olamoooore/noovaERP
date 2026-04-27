<?php

return [
    /*
    |--------------------------------------------------------------------------
    | POS Alert Thresholds
    |--------------------------------------------------------------------------
    */
    'pos' => [
        // Payment failure rate > 1% triggers critical alert
        'payment_failure_rate_threshold' => env('POS_PAYMENT_FAILURE_RATE_THRESHOLD', 0.01),

        // Sync failure rate > 5% triggers critical alert
        'sync_failure_rate_threshold' => env('POS_SYNC_FAILURE_RATE_THRESHOLD', 0.05),

        // Sync queue depth > 100 triggers warning alert
        'sync_queue_depth_threshold' => env('POS_SYNC_QUEUE_DEPTH_THRESHOLD', 100),

        // GL posting failure count > 0 triggers critical alert
        'gl_posting_failure_threshold' => env('POS_GL_POSTING_FAILURE_THRESHOLD', 0),
    ],
];
