<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\BudgetService;
use App\Services\EntityAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Issue #92: Budget Management API — CRUD, approval and variance.
 */
class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly EntityAccessService $entityAccessService,
    ) {}

    /**
     * Hostile re-review (#92): entity-scoped users must not create or read
     * budgets for entities they cannot access. Empty grants keep the platform's
     * fail-open behaviour for single-entity tenants.
     */
    private function assertEntityAccess(Request $request, ?int $legalEntityId): void
    {
        if ($legalEntityId === null) {
            return;
        }

        $user = $request->user();

        if (! $user) {
            return;
        }

        $accessible = $this->entityAccessService->accessibleEntityIds((int) $user->id);

        if ($accessible !== [] && ! in_array($legalEntityId, $accessible, true)) {
            abort(403, 'You do not have access to this legal entity.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = Budget::withCount('lines')->orderByDesc('fiscal_year')->orderByDesc('id');

        // Hostile re-review (#92): list only budgets inside the requester's
        // entity scope (empty grants keep fail-open).
        $user = $request->user();
        $accessible = $user ? $this->entityAccessService->accessibleEntityIds((int) $user->id) : [];

        if ($accessible !== []) {
            $query->where(function ($inner) use ($accessible) {
                $inner->whereIn('legal_entity_id', $accessible)->orWhereNull('legal_entity_id');
            });
        }

        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', (int) $request->input('fiscal_year'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'fiscal_year' => 'required|integer|min:2000|max:2100',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.account_id' => 'required|integer|exists:chart_of_accounts,id',
            'lines.*.amount' => 'required|numeric',
            'lines.*.cost_center_id' => 'nullable|integer',
            'lines.*.notes' => 'nullable|string',
        ]);

        $this->assertEntityAccess($request, $validated['legal_entity_id'] ?? null);

        $validated['created_by'] = Auth::id();

        try {
            $budget = $this->budgetService->create($validated);

            return response()->json([
                'message' => 'Budget created successfully',
                'data' => $budget,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, int $budget): JsonResponse
    {
        $model = Budget::with(['lines.account', 'createdBy'])->find($budget);

        if (! $model) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        $this->assertEntityAccess($request, $model->legal_entity_id);

        return response()->json(['data' => $model]);
    }

    public function update(Request $request, int $budget): JsonResponse
    {
        $model = Budget::find($budget);

        if (! $model) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'period_start' => 'sometimes|date',
            'period_end' => 'sometimes|date',
            'legal_entity_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'lines' => 'sometimes|array|min:1',
            'lines.*.account_id' => 'required_with:lines|integer|exists:chart_of_accounts,id',
            'lines.*.amount' => 'required_with:lines|numeric',
            'lines.*.cost_center_id' => 'nullable|integer',
            'lines.*.notes' => 'nullable|string',
        ]);

        try {
            return response()->json([
                'message' => 'Budget updated successfully',
                'data' => $this->budgetService->update($model, $validated),
            ]);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(int $budget): JsonResponse
    {
        $model = Budget::find($budget);

        if (! $model) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        if ($model->isApproved()) {
            return response()->json(['message' => 'Approved budgets cannot be deleted. Archive it instead.'], 422);
        }

        $model->delete();

        return response()->json(['message' => 'Budget deleted successfully']);
    }

    /**
     * Hostile re-review (#92): the delete message says "Archive it instead"
     * but no archive endpoint existed.
     */
    public function archive(Request $request, int $budget): JsonResponse
    {
        $model = Budget::find($budget);

        if (! $model) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        if ($model->status === 'archived') {
            return response()->json(['message' => 'Budget is already archived'], 422);
        }

        $this->assertEntityAccess($request, $model->legal_entity_id);

        $model->update(['status' => 'archived']);

        return response()->json([
            'message' => 'Budget archived successfully',
            'data' => $model->fresh(),
        ]);
    }

    public function approve(Request $request, int $budget): JsonResponse
    {
        $model = Budget::find($budget);

        if (! $model) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        $this->assertEntityAccess($request, $model->legal_entity_id);

        try {
            return response()->json([
                'message' => 'Budget approved successfully',
                'data' => $this->budgetService->approve($model, Auth::id()),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function variance(Request $request, int $budget): JsonResponse
    {
        $model = Budget::find($budget);

        if (! $model) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        $this->assertEntityAccess($request, $model->legal_entity_id);

        return response()->json(['data' => $this->budgetService->variance($model)]);
    }
}
