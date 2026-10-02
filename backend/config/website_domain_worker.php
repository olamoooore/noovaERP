<?php

return [
    'helper' => env('WEBSITE_DOMAIN_HELPER', '/usr/local/sbin/noova-domain-ingress'),
    'sudo' => env('WEBSITE_DOMAIN_SUDO', '/usr/bin/sudo'),
    'acme_email' => env('WEBSITE_DOMAIN_ACME_EMAIL'),
    'operation_timeout' => (int) env('WEBSITE_DOMAIN_OPERATION_TIMEOUT', 300),
    'stale_after_minutes' => (int) env('WEBSITE_DOMAIN_STALE_AFTER_MINUTES', 30),
    'health_ip' => env('WEBSITE_DOMAIN_HEALTH_IP', '127.0.0.1'),
];
