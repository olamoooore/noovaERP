<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Ecommerce\Listeners\AwardLoyaltyPointsOnPayment;
use Modules\PaymentGateway\Events\PaymentSuccessful;

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
