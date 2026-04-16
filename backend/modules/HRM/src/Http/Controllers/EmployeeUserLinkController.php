<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Requests\StoreEmployeeUserLinkRequest;
use Modules\HRM\Http\Resources\EmployeeUserLinkResource;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\EmployeeUserLink;

class EmployeeUserLinkController extends Controller
{
    use AuthorizesRequests;

    public function index(Employee $employee): JsonResponse
    {
        $this->authorize('viewAny', EmployeeUserLink::class);

        $links = $employee->userLinks()->with('user')->get();

        return EmployeeUserLinkResource::collection($links)->response();
    }

    public function store(StoreEmployeeUserLinkRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('create', EmployeeUserLink::class);
        $data = $request->validated();

        // Guard: prevent duplicate (employee_id, user_id) — return 422 with clear message
        $exists = $employee->userLinks()->where('user_id', $data['user_id'])->exists();
        if ($exists) {
            return response()->json([
                'message' => 'This user is already linked to this employee.',
            ], 422);
        }

        $link = $employee->userLinks()->create([
            'user_id' => $data['user_id'],
            'type'    => $data['type'] ?? 'primary',
        ]);

        $link->load('user');

        return (new EmployeeUserLinkResource($link))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Employee $employee, EmployeeUserLink $link): JsonResponse
    {
        $this->authorize('delete', $link);

        $link->delete();

        return response()->json(['message' => 'User link removed.']);
    }
}
