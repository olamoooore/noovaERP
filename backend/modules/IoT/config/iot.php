<?php

return [
    'drivers' => [
        'printer' => [
            'escpos' => \Modules\IoT\Services\Drivers\EscPosPrinterDriver::class,
            'agent' => \Modules\IoT\Services\Drivers\AgentPrinterDriver::class,
            'gprinter' => \Modules\IoT\Services\Drivers\GprinterLabelDriver::class,
        ],
        'terminal' => [
            'hitpay' => \Modules\IoT\Services\Drivers\HitPayTerminalDriver::class,
            'stripe' => \Modules\IoT\Services\Drivers\StripeTerminalDriver::class,
            'agent' => \Modules\IoT\Services\Drivers\AgentTerminalDriver::class,
            'mock' => \Modules\IoT\Services\Drivers\AgentTerminalDriver::class,
        ],
        'scanner' => [
            'agent' => \Modules\IoT\Services\Drivers\AgentScannerDriver::class,
        ],
    ],

    'health_check_interval' => env('IOT_HEALTH_CHECK_INTERVAL', 300), // seconds
    'log_retention_days' => env('IOT_LOG_RETENTION_DAYS', 90),
    'agent_heartbeat_timeout' => env('IOT_AGENT_HEARTBEAT_TIMEOUT', 60), // seconds

    'qr_code' => [
        'default_size' => 200,
        'error_correction' => 'H', // L, M, Q, H
        'margin' => 4,
    ],

    'barcode' => [
        'supported_formats' => ['ean13', 'upc', 'code128', 'code39', 'gs1-128', 'qr'],
        'default_format' => 'code128',
    ],

    'hitpay' => [
        'api_url' => env('HITPAY_API_URL', 'https://api.hit-pay.com/v1'),
        'webhook_url' => env('HITPAY_WEBHOOK_URL'),
        'supported_currencies' => ['SGD', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'HKD', 'MYR'],
        'supported_payment_methods' => [
            'wifi_card_reader',
            'paynow_online',
            'grabpay',
            'wechat',
            'alipay',
        ],
    ],

    'agent' => [
        'default_url' => env('IOT_AGENT_URL', 'http://localhost:3002'),
        'timeout' => env('IOT_AGENT_TIMEOUT', 30), // seconds
        'heartbeat_interval' => env('IOT_AGENT_HEARTBEAT_INTERVAL', 60), // seconds
        'supported_vendors' => [
            'ingenico' => 'Ingenico',
            'verifone' => 'Verifone',
            'pax' => 'PAX',
            'zebra' => 'Zebra',
            'honeywell' => 'Honeywell',
            'epson' => 'Epson',
            'star' => 'Star Micronics',
        ],
    ],
];
