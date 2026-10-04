<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Operations console (#914)
    |--------------------------------------------------------------------------
    | Per-severity SLA targets, in hours from the moment an exception is
    | raised to the moment it must be resolved. These are OPERATIONAL policy
    | constants powering the console's SLA age badge and breach count — they
    | are not statutory service levels and a deployment may tune them.
    */
    'sla_hours' => [
        'critical' => 4,
        'high' => 24,
        'medium' => 72,
        'low' => 168,
        'default' => 24,
    ],
];
