<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Providers;

use Illuminate\Support\ServiceProvider;

class EcommerceServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // P5 code deletion (FR-ARC-06): the legacy ProcessAllWebOrders
        // repair command (and the whole checkout surface) is gone. The
        // module now carries ONLY the retained read surface — admin
        // order reads, the guest order lookup, and the read-only
        // customer order history — plus migrations kept as history.
    }
}
