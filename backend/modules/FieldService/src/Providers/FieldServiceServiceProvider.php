<?php

namespace Modules\FieldService\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\Technician;
use Modules\FieldService\Models\Team;

class FieldServiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Route model binding
        $this->app['router']->model('workOrder', WorkOrder::class);
        $this->app['router']->model('technician', Technician::class);
        $this->app['router']->model('team', Team::class);
    }
}
