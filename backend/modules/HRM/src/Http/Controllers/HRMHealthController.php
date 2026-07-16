<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HRMHealthController extends Controller
{
    public function check(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'module' => 'HRM',
            'version' => '1.0.0',
            'tenant' => tenant('id') ?? 'unknown',
        ]);
    }
}
