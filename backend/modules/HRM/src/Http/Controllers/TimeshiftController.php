<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\HRM\Models\Timeshift;
use Modules\HRM\Http\Requests\StoreTimeshiftRequest;
use Modules\HRM\Http\Requests\UpdateTimeshiftRequest;
use Modules\HRM\Http\Resources\TimeshiftResource;

class TimeshiftController extends Controller
{
    public function index(): JsonResponse
    {
        $timeshifts = Timeshift::orderByDesc('is_default')->orderBy('name')->get();

        return TimeshiftResource::collection($timeshifts)->response();
    }

    public function store(StoreTimeshiftRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Only one default allowed — clear existing default first
        if (!empty($data['is_default'])) {
            Timeshift::where('is_default', true)->update(['is_default' => false]);
        }

        $timeshift = Timeshift::create($data);

        return (new TimeshiftResource($timeshift))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Timeshift $timeshift): JsonResponse
    {
        return (new TimeshiftResource($timeshift))->response();
    }

    public function update(UpdateTimeshiftRequest $request, Timeshift $timeshift): JsonResponse
    {
        $data = $request->validated();

        if (!empty($data['is_default'])) {
            Timeshift::where('is_default', true)
                ->where('id', '!=', $timeshift->id)
                ->update(['is_default' => false]);
        }

        $timeshift->update($data);

        return (new TimeshiftResource($timeshift->fresh()))->response();
    }

    public function destroy(Timeshift $timeshift): JsonResponse
    {
        $timeshift->delete();

        return response()->json(null, 204);
    }
}
