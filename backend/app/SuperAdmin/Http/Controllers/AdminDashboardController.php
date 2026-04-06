<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $tenantCount      = DB::table('tenants')->count();
        $planFamilyCount  = DB::table('plan_families')->count();
        $moduleCount      = DB::table('module_catalog')->count();

        return response()->json([
            'status'            => 'ok',
            'version'           => config('app.version', '1.0.0'),
            'tenant_count'      => $tenantCount,
            'plan_family_count' => $planFamilyCount,
            'module_count'      => $moduleCount,
            'phase'             => 'Phase 1 — Data Foundation',
            'message'           => 'Plan catalog and module catalog are operational. Tenant 360 coming in Phase 2.',
        ]);
    }
}
