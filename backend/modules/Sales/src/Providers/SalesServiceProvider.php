<?php

namespace Modules\Sales\Providers;

use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \Modules\Sales\Services\OrderLifecycleService::class,
            \Modules\Sales\Services\OrderLifecycleService::class,
        );

        $this->app->bind(
            \Modules\Sales\Services\SalesOrderService::class,
            \Modules\Sales\Services\SalesOrderService::class,
        );

        $this->app->bind(
            \Modules\Sales\Services\CreditCheckService::class,
            \Modules\Sales\Services\CreditCheckService::class,
        );
    }

    public function boot(): void
    {
        $this->registerAuditObservers();
    }

    /**
     * Issue #895 (SALES-PLAT / PC1): the Sales documents and their lines must
     * carry a field-level audit trail. Register the shared platform observer
     * from the module that owns the domain instead of the platform provider's
     * hard-coded list.
     */
    private function registerAuditObservers(): void
    {
        $observer = \App\Observers\AuditTrailObserver::class;

        $models = [
            \App\Models\Invoice::class,
            \App\Models\InvoiceLine::class,
            \App\Models\Quotation::class,
            \App\Models\QuotationLine::class,
            \App\Models\Payment::class,
            \App\Models\Customer::class,
            \App\Models\CustomerCreditNote::class,
            \App\Models\CustomerCreditNoteLine::class,
            \Modules\Sales\Models\SalesOrder::class,
            \Modules\Sales\Models\SalesOrderLine::class,
            \Modules\Sales\Models\DeliveryOrder::class,
            \Modules\Sales\Models\DeliveryOrderLine::class,
        ];

        foreach ($models as $model) {
            if (class_exists($model)) {
                $model::observe($observer);
            }
        }
    }
}
