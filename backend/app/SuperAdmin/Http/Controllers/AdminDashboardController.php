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
        $tenantCount = DB::table('tenants')->count();

        return response()->json([
            'status'       => 'ok',
            'version'      => config('app.version', '1.0.0'),
            'tenant_count' => $tenantCount,
            'phase'        => 'Phase 0 — Foundation',
            'message'      => 'Superadmin Control Plane is operational. Full dashboard coming in Phase 2.',
        ]);
    }
}
