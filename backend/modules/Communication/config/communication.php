<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Communication Module Configuration
    |--------------------------------------------------------------------------
    |
    | Central communication bus configuration for NoovaERP.
    | Inspired by Odoo's mail.message architecture.
    |
    */

    // Default channels enabled for new subscriptions
    'default_channels' => ['in_app', 'email'],

    // Available channel drivers
    'channels' => [
        'email' => [
            'enabled' => true,
            'driver' => 'smtp', // smtp, ses, mailgun, sendgrid
            'batch_size' => 50,
            'rate_limit' => 100, // per minute
        ],
        'in_app' => [
            'enabled' => true,
            'max_unread' => 500,
        ],
        'sms' => [
            'enabled' => false,
            'driver' => null, // twilio, vonage, africastalking
            'from' => null,
        ],
        'whatsapp' => [
            'enabled' => false,
            'driver' => null, // meta, twilio
            'from' => null,
        ],
        'push' => [
            'enabled' => false,
            'driver' => null, // fcm, onesignal
        ],
        'slack' => [
            'enabled' => false,
            'webhook_url' => null,
        ],
    ],

    // Outbox / Delivery queue settings
    'outbox' => [
        'retry_max' => 5,
        'retry_backoff' => 'exponential', // linear, exponential
        'retry_delay_seconds' => 60,
        'batch_size' => 100,
        'cleanup_days' => 90, // delete sent items after N days
    ],

    // Template settings
    'templates' => [
        'cache' => true,
        'cache_ttl' => 3600, // seconds
    ],

    // Digest settings
    'digest' => [
        'enabled' => true,
        'schedule' => '08:00', // default send time
        'timezone' => 'UTC',
    ],

    // Quiet hours (don't send notifications during these hours)
    'quiet_hours' => [
        'enabled' => false,
        'start' => '22:00',
        'end' => '07:00',
        'timezone' => 'UTC',
        'except_channels' => ['in_app'], // always deliver these
    ],

    // Auto-subscribe settings
    'auto_subscribe' => [
        'creator' => true,   // auto-subscribe record creator
        'assignee' => true,  // auto-subscribe assigned user
        'mentioned' => true, // auto-subscribe @mentioned users
    ],

    // Message retention
    'retention' => [
        'system_messages_days' => 365,
        'comment_messages_days' => null, // never delete
        'email_messages_days' => 730,
    ],
];
