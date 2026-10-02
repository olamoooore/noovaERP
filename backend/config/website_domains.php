<?php

$centralHosts = array_merge(
    explode(',', (string) env('CENTRAL_DOMAINS', 'localhost')),
    explode(',', (string) env('WEBSITE_DOMAIN_CENTRAL_HOSTS', 'noovaerp.example.com')),
);

return [
    'cname_target' => env('WEBSITE_DOMAIN_CNAME_TARGET', 'sites.noovaerp.example.com'),
    'apex_ipv4' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBSITE_DOMAIN_APEX_IPV4', ''))))),
    'apex_ipv6' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBSITE_DOMAIN_APEX_IPV6', ''))))),
    'challenge_ttl_hours' => (int) env('WEBSITE_DOMAIN_CHALLENGE_TTL_HOURS', 72),
    'quarantine_days' => (int) env('WEBSITE_DOMAIN_QUARANTINE_DAYS', 7),
    'reserved_suffixes' => ['localhost', 'local', 'internal', 'test', 'invalid', 'example'],
    'central_hosts' => array_values(array_unique(array_filter(array_map('trim', $centralHosts)))),
];
