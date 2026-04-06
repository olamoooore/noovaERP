<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use App\Models\Department;
use App\Models\Location;

class LookupController extends Controller
{
    public function departments(): JsonResponse
    {
        $departments = Department::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json(['data' => $departments]);
    }

    public function locations(): JsonResponse
    {
        $locations = Location::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'country']);

        return response()->json(['data' => $locations]);
    }
}
