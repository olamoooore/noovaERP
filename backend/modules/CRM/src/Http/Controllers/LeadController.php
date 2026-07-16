<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CRM\Models\Lead;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::with(['assignedTo', 'createdBy'])
            ->withCount('activities');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('company', 'ilike', "%{$search}%");
            });
        }

        $leads = $query->orderBy('created_at', 'desc')->paginate(25);

        return response()->json($leads);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'status' => 'nullable|in:'.implode(',', Lead::STATUSES),
            'source' => 'nullable|in:'.implode(',', Lead::SOURCES),
            'estimated_value' => 'nullable|numeric|min:0',
            'score' => 'nullable|integer|min:0|max:100',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'expected_close_date' => 'nullable|date',
        ]);

        $validated['created_by'] = auth()->id();

        $lead = Lead::create($validated);

        return response()->json($lead->load(['assignedTo', 'createdBy']), 201);
    }

    public function show(Lead $lead)
    {
        return response()->json(
            $lead->load(['assignedTo', 'createdBy', 'activities' => function ($q) {
                $q->with('user')->orderBy('created_at', 'desc')->limit(20);
            }])
        );
    }

    public function update(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'status' => 'nullable|in:'.implode(',', Lead::STATUSES),
            'source' => 'nullable|in:'.implode(',', Lead::SOURCES),
            'estimated_value' => 'nullable|numeric|min:0',
            'score' => 'nullable|integer|min:0|max:100',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'expected_close_date' => 'nullable|date',
            'last_contacted_at' => 'nullable|date',
        ]);

        $lead->update($validated);

        return response()->json($lead->fresh(['assignedTo', 'createdBy']));
    }

    public function destroy(Lead $lead)
    {
        // Delete associated activities first
        $lead->activities()->delete();
        $lead->delete();

        return response()->json(['message' => 'Lead deleted']);
    }

    public function stats()
    {
        $stats = [
            'total' => Lead::count(),
            'new' => Lead::where('status', 'new')->count(),
            'contacted' => Lead::where('status', 'contacted')->count(),
            'interested' => Lead::where('status', 'interested')->count(),
            'proposal' => Lead::where('status', 'proposal')->count(),
            'won' => Lead::where('status', 'won')->count(),
            'lost' => Lead::where('status', 'lost')->count(),
            'total_value' => Lead::whereNotIn('status', ['lost'])->sum('estimated_value'),
            'conversion_rate' => Lead::count() > 0
                ? round((Lead::where('status', 'won')->count() / Lead::count()) * 100, 1)
                : 0,
        ];

        return response()->json($stats);
    }
}
