<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Modules\FieldService\Models\Technician;
use Modules\FieldService\Models\WorkOrder;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $query = Technician::with(['user', 'team', 'defaultWarehouse'])
            ->where('is_active', true);

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->input('team_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        return response()->json($query->get());
    }

    public function show(int $technician_id)
    {
        $technician = $this->findTechnicianOrFail($technician_id);

        return response()->json($this->buildTechnicianPayload($technician));
    }

    public function stats(int $technician_id)
    {
        $technician = $this->findTechnicianOrFail($technician_id);

        return response()->json($this->buildTechnicianPayload($technician)['stats']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:fs_technicians,user_id',
            'team_id' => 'nullable|exists:fs_teams,id',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:100',
            'certifications' => 'nullable|array',
            'certifications.*' => 'string|max:100',
            'default_warehouse_id' => 'nullable|exists:warehouses,id',
            'hourly_rate' => 'nullable|numeric|min:0',
            'vehicle_registration' => 'nullable|string|max:50',
        ]);

        $technician = Technician::create($validated);

        return response()->json($technician->load(['user', 'team']), 201);
    }

    public function update(Request $request, int $technician_id)
    {
        $technician = $this->findTechnicianOrFail($technician_id);

        $validated = $request->validate([
            'team_id' => 'nullable|exists:fs_teams,id',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:100',
            'certifications' => 'nullable|array',
            'certifications.*' => 'string|max:100',
            'default_warehouse_id' => 'nullable|exists:warehouses,id',
            'hourly_rate' => 'nullable|numeric|min:0',
            'vehicle_registration' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $technician->update($validated);

        return response()->json($technician->fresh(['user', 'team']));
    }

    public function destroy(int $technician_id)
    {
        $technician = $this->findTechnicianOrFail($technician_id);

        $technician->update(['is_active' => false]);

        return response()->json(['message' => 'Technician deactivated.']);
    }

    /**
     * List users that are not yet registered as technicians.
     */
    public function nonTechnicians()
    {
        $existing = Technician::pluck('user_id');

        $users = User::whereNotIn('id', $existing)
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    private function buildTechnicianPayload(Technician $technician): array
    {
        $technician->load(['user', 'team', 'defaultWarehouse']);

        // Attach work order stats
        $stats = WorkOrder::where('lead_technician_id', $technician->user_id)
            ->selectRaw('
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as active_jobs,
                ROUND(AVG(CASE WHEN customer_rating IS NOT NULL THEN customer_rating END), 2) as avg_rating
            ')
            ->first();

        $data = $technician->toArray();
        $data['stats'] = $stats;

        return $data;
    }

    private function findTechnicianOrFail(int $technicianId): Technician
    {
        $technician = Technician::query()->find($technicianId);

        if (! $technician) {
            throw (new ModelNotFoundException)->setModel(Technician::class, [$technicianId]);
        }

        return $technician;
    }
}
