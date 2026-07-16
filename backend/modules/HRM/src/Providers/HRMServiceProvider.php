<?php

namespace Modules\HRM\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\HRM\Models\AttendanceDailySummary;
use Modules\HRM\Models\AttendanceEvent;
use Modules\HRM\Models\EmployeeBiometricSubject;
use Modules\HRM\Models\EmployeeUserLink;
use Modules\HRM\Policies\AttendanceEventPolicy;
use Modules\HRM\Policies\AttendanceSummaryPolicy;
use Modules\HRM\Policies\EmployeeBiometricSubjectPolicy;
use Modules\HRM\Policies\EmployeeUserLinkPolicy;

class HRMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/hrm.php', 'hrm');
    }

    public function boot(): void
    {
        // Routes are loaded via ModuleManager::tenantApiRouteFiles() in routes/tenant.php.
        // Tenant migrations are registered centrally via config/tenancy.php migration_parameters.

        Gate::policy(EmployeeUserLink::class, EmployeeUserLinkPolicy::class);
        Gate::policy(EmployeeBiometricSubject::class, EmployeeBiometricSubjectPolicy::class);
        Gate::policy(AttendanceEvent::class, AttendanceEventPolicy::class);
        Gate::policy(AttendanceDailySummary::class, AttendanceSummaryPolicy::class);
    }
}
