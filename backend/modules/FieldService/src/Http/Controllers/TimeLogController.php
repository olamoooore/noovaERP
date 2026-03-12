<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\TimeLog;

class TimeLogController extends Controller
{
    public function index(WorkOrder $workOrder)
    {
        return response()->json(
            $workOrder->timeLogs()->with('user')->orderBy('date', 'desc')->get()
        );
    }

    public function store(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'description'    => 'nullable|string|max:255',
            'date'           => 'required|date',
            'started_at'     => 'nullable|date',
            'ended_at'       => 'nullable|date|after:started_at',
            'duration_hours' => 'required|numeric|min:0.01|max:24',
            'is_billable'    => 'nullable|boolean',
            'hourly_rate'    => 'nullable|numeric|min:0',
        ]);

        $log = $workOrder->timeLogs()->create(array_merge($validated, [
            'work_order_id' => $workOrder->id,
            'user_id'       => Auth::id(),
        ]));

        return response()->json($log->load('user'), 201);
    }

    public function update(Request $request, WorkOrder $workOrder, TimeLog $timeLog)
    {
        $this->ensureLogBelongs($workOrder, $timeLog);

        $validated = $request->validate([
            'description'    => 'nullable|string|max:255',
            'date'           => 'nullable|date',
            'started_at'     => 'nullable|date',
            'ended_at'       => 'nullable|date',
            'duration_hours' => 'nullable|numeric|min:0.01|max:24',
            'is_billable'    => 'nullable|boolean',
            'hourly_rate'    => 'nullable|numeric|min:0',
        ]);

        $timeLog->update($validated);

        return response()->json($timeLog->fresh('user'));
    }

    public function destroy(WorkOrder $workOrder, TimeLog $timeLog)
    {
        $this->ensureLogBelongs($workOrder, $timeLog);
        $timeLog->delete();

        return response()->json(['message' => 'Time log removed.']);
    }

    private function ensureLogBelongs(WorkOrder $workOrder, TimeLog $timeLog): void
    {
        if ($timeLog->work_order_id !== $workOrder->id) {
            abort(404, 'Time log not found on this work order.');
        }
    }
}
