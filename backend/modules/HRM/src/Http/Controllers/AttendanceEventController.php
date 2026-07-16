<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Requests\IngestAttendanceEventRequest;
use Modules\HRM\Http\Resources\AttendanceEventResource;
use Modules\HRM\Models\AttendanceEvent;
use Modules\HRM\Services\AttendanceSummaryService;

class AttendanceEventController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private AttendanceSummaryService $summaryService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceEvent::class);

        $query = AttendanceEvent::with('employee')->latest('captured_at');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->string('event_type'));
        }

        if ($request->filled('date_from')) {
            $query->where('captured_at', '>=', $request->string('date_from').' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('captured_at', '<=', $request->string('date_to').' 23:59:59');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return AttendanceEventResource::collection($query->paginate(20))->response();
    }

    public function ingest(IngestAttendanceEventRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceEvent::class);

        $data = $request->validated();

        // Idempotency: reject duplicate source_event_id
        $duplicate = AttendanceEvent::where('source_event_id', $data['source_event_id'])->exists();
        if ($duplicate) {
            return response()->json([
                'message' => 'Event already ingested (duplicate source_event_id).',
            ], 409);
        }

        $event = AttendanceEvent::create(array_merge($data, ['status' => 'pending']));

        $this->summaryService->processEvent($event);

        $event->load('employee');

        return (new AttendanceEventResource($event))
            ->response()
            ->setStatusCode(201);
    }
}
