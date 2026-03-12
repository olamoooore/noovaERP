<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\FieldService\Models\Team;

class TeamController extends Controller
{
    public function index()
    {
        return response()->json(
            Team::with(['leader', 'technicians.user'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function show(Team $team)
    {
        $team->load(['leader', 'technicians.user']);
        return response()->json($team);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'leader_id'   => 'nullable|exists:users,id',
            'color'       => 'nullable|string|max:7',
        ]);

        $team = Team::create($validated);

        return response()->json($team->load(['leader', 'technicians.user']), 201);
    }

    public function update(Request $request, Team $team)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'leader_id'   => 'nullable|exists:users,id',
            'color'       => 'nullable|string|max:7',
            'is_active'   => 'nullable|boolean',
        ]);

        $team->update($validated);

        return response()->json($team->fresh(['leader', 'technicians.user']));
    }

    public function destroy(Team $team)
    {
        $team->update(['is_active' => false]);
        return response()->json(['message' => 'Team deactivated.']);
    }
}
