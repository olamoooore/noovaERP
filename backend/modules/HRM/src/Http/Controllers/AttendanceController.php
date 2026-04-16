<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HRM\Models\Attendance;
use Modules\HRM\Http\Requests\StoreAttendanceRequest;
use Modules\HRM\Http\Requests\UpdateAttendanceRequest;
use Modules\HRM\Http\Resources\AttendanceResource;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Attendance::with('employee')->latest('date')->latest('id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }

        $records = $query->paginate(30);

        return AttendanceResource::collection($records)->response();
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $attendance = Attendance::create($request->validated());
        $attendance->load('employee');

        return (new AttendanceResource($attendance))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $attendance->load('employee');

        return (new AttendanceResource($attendance))->response();
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance): JsonResponse
    {
        $attendance->update($request->validated());
        $attendance->load('employee');

        return (new AttendanceResource($attendance))->response();
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return response()->json(null, 204);
    }
}
