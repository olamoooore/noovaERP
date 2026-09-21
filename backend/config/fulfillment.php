<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Simulated carriers (Issue #146)
    |--------------------------------------------------------------------------
    |
    | None of the bundled carriers (DHL / FedEx / Aramex / GDEX) are connected
    | to a real carrier API: shipment creation, cancellation and tracking are
    | simulated. Simulated shipment creation/tracking is therefore only allowed
    | when this flag is true, so production tenants cannot be handed fabricated
    | tracking numbers. Defaults to enabled outside production (local, demo,
    | testing); set FULFILLMENT_SIMULATED_CARRIERS_ENABLED=true explicitly to
    | allow sandbox use in production.
    |
    */
    'simulated_carriers_enabled' => env(
        'FULFILLMENT_SIMULATED_CARRIERS_ENABLED',
        env('APP_ENV', 'production') !== 'production'
    ),

    /*
    |--------------------------------------------------------------------------
    | Automated tracking sync (Issue #146)
    |--------------------------------------------------------------------------
    |
    | The scheduled/automated tracking sync must stay disabled until a real
    | carrier tracking integration exists — syncing simulated data writes
    | fabricated statuses into the shipments table.
    |
    */
    'tracking_sync_enabled' => env('FULFILLMENT_TRACKING_SYNC_ENABLED', false),
];
