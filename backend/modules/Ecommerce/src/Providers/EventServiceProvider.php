<?php

namespace Modules\Ecommerce\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\PaymentGateway\Events\PaymentSuccessful;
use Modules\Ecommerce\Listeners\AwardLoyaltyPointsOnPayment;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        PaymentSuccessful::class => [
            AwardLoyaltyPointsOnPayment::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
