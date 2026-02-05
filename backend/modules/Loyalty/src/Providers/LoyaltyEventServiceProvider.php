<?php

namespace Modules\Loyalty\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class LoyaltyEventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected $listen = [
        // When invoice payment event is fired, award loyalty points
        // Note: The actual event class should be created by the Invoice/Payment module
        // For now, we're documenting the expected event structure
        'App\Events\InvoicePaymentReceived' => [
            'Modules\Loyalty\Listeners\InvoicePaymentListener',
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }
}
