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

// For API routes, use SetTenantFromUserPreference to identify tenant from user's default company
Route::middleware([
    'api',
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    PreventAccessFromCentralDomains::class,
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
])->prefix('api/v1')->group(function () {
    $modules = app(\App\Modules\ModuleManager::class);

    foreach ($modules->tenantApiRouteFiles() as $routesFile) {
        require $routesFile;
    }
});
