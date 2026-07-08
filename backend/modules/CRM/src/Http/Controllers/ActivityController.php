<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Lead $lead)
    {
        $activities = $lead->activities()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($activities);
    }

    public function store(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'type' => 'required|in:' . implode(',', Activity::TYPES),
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'activity_date' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:0',
            'outcome' => 'nullable|string|max:255',
        ]);

        $validated['lead_id'] = $lead->id;
        $validated['user_id'] = auth()->id();
        $validated['activity_date'] = $validated['activity_date'] ?? now();

        $activity = Activity::create($validated);

        // Update lead's last_contacted_at
        $lead->update(['last_contacted_at' => now()]);

        return response()->json($activity->load('user'), 201);
    }

    public function update(Request $request, Activity $activity)
    {
        $validated = $request->validate([
            'type' => 'sometimes|in:' . implode(',', Activity::TYPES),
            'subject' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'activity_date' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:0',
            'outcome' => 'nullable|string|max:255',
        ]);

        $activity->update($validated);

        return response()->json($activity->fresh('user'));
    }

    public function destroy(Activity $activity)
    {
        $activity->delete();
        return response()->json(['message' => 'Activity deleted']);
    }
}
