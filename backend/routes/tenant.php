<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant API Routes
|--------------------------------------------------------------------------
| All routes here are automatically scoped to current tenant
| Schema isolation ensures data security
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->prefix('api/v1')->group(function () {
    $modules = app(\App\Modules\ModuleManager::class);

    foreach ($modules->tenantApiRouteFiles() as $routesFile) {
        require $routesFile;
    }
});
