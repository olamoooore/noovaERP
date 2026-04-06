<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\HRM\Models\Employee;
use Modules\HRM\Http\Requests\StoreEmployeeRequest;
use Modules\HRM\Http\Requests\UpdateEmployeeRequest;
use Modules\HRM\Http\Resources\EmployeeResource;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $employees = Employee::with(['user', 'department', 'location', 'manager'])
            ->latest()
            ->paginate(20);

        return EmployeeResource::collection($employees)->response();
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = Employee::create($request->validated());
        $employee->load(['user', 'department', 'location', 'manager']);

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load(['user', 'department', 'location', 'manager', 'directReports']);

        return (new EmployeeResource($employee))->response();
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee->update($request->validated());
        $employee->load(['user', 'department', 'location', 'manager']);

        return (new EmployeeResource($employee))->response();
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();

        return response()->json(null, 204);
    }
}
