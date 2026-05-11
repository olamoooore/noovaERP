<?php

return [
    // Tenant-specific module overrides applied by ops:tenant-guardrails.
    // Keep this list explicit and idempotent so local/staging/prod stay aligned.
    'tenant_module_overrides' => [
        '147b57e9-be37-4478-a55e-a382816cf7ec' => [
            'include' => [
                'ai',
                'communication',
                'core',
                'ecommerce',
                'event_management',
                'inventory',
                'iot',
                'loyalty',
                'payment_gateway',
                'pos',
                'sales',
                'website-builder',
                'whatsapp',
            ],
            'exclude' => [],
        ],
    ],

    // Baseline payment gateways that must exist in every environment.
    'payment_gateways' => [
        [
            'gateway_name' => 'offline',
            'gateway_type' => 'offline',
            'is_active' => true,
            'is_test_mode' => false,
        ],
    ],
];
