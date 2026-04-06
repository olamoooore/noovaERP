<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Requests\StoreEmployeeBiometricSubjectRequest;
use Modules\HRM\Http\Resources\EmployeeBiometricSubjectResource;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\EmployeeBiometricSubject;

class EmployeeBiometricSubjectController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all biometric subjects for an employee.
     */
    public function index(Employee $employee): JsonResponse
    {
        $this->authorize('viewAny', EmployeeBiometricSubject::class);

        $subjects = $employee->biometricSubjects()->orderBy('type')->get();

        return EmployeeBiometricSubjectResource::collection($subjects)->response();
    }

    /**
     * Register a new biometric subject mapping for an employee.
     * Stores only the device-assigned subject_code — no raw biometric data.
     */
    public function store(StoreEmployeeBiometricSubjectRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('create', EmployeeBiometricSubject::class);
        $subject = $employee->biometricSubjects()->create(array_merge(
            $request->validated(),
            ['status' => $request->validated()['status'] ?? 'pending']
        ));

        return (new EmployeeBiometricSubjectResource($subject))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Deactivate (soft-delete) a biometric subject mapping.
     */
    public function destroy(Employee $employee, EmployeeBiometricSubject $subject): JsonResponse
    {
        $this->authorize('delete', $subject);

        // Ensure the subject belongs to the given employee
        if ($subject->employee_id !== $employee->id) {
            return response()->json(['message' => 'Biometric subject not found for this employee.'], 404);
        }

        $subject->delete();

        return response()->json(['message' => 'Biometric subject deactivated.']);
    }
}
