<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Resources\AttendanceDailySummaryResource;
use Modules\HRM\Models\AttendanceDailySummary;

class AttendanceSummaryController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDailySummary::class);

        $query = AttendanceDailySummary::with('employee')->orderByDesc('date');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->string('date_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->boolean('has_exception')) {
            $query->where('has_exception', true);
        }

        return AttendanceDailySummaryResource::collection($query->paginate(20))->response();
    }

    public function show(AttendanceDailySummary $summary): JsonResponse
    {
        $this->authorize('view', $summary);

        $summary->load('employee');

        return (new AttendanceDailySummaryResource($summary))->response();
    }
}
