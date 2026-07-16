<?php

declare(strict_types=1);

namespace Modules\CRM\Providers;

use Illuminate\Support\ServiceProvider;

class CRMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/crm.php', 'crm');
    }

    public function boot(): void
    {
        // Routes loaded via ModuleManager::tenantApiRouteFiles() in routes/tenant.php.
        // Tenant migrations registered centrally via config/tenancy.php migration_parameters.
    }
}
