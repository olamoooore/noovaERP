<?php

namespace Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(StockReservationService::class);
    }

    public function boot(): void
    {
        // Register model bindings for route model binding
        $this->app['router']->model('brand', \Modules\Inventory\Models\Brand::class);
        $this->app['router']->model('supplier', \Modules\Inventory\Models\Supplier::class);
        $this->app['router']->model('purchaseOrder', \Modules\Inventory\Models\PurchaseOrder::class);
        $this->app['router']->model('warehouseTransfer', \Modules\Inventory\Models\WarehouseTransfer::class);
    }
}
