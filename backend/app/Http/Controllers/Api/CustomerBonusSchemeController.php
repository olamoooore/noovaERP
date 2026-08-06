<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerBonusScheme;
use App\Models\BonusScheme;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBonusSchemeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerBonusScheme::with(['customer', 'bonusScheme', 'usages'])
            ->latest();

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('bonus_scheme_id')) {
            $query->where('bonus_scheme_id', $request->bonus_scheme_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', function ($q2) use ($search) {
                    $q2->where('company_name', 'ilike', "%{$search}%");
                })->orWhereHas('bonusScheme', function ($q2) use ($search) {
                    $q2->where('name', 'ilike', "%{$search}%")
                        ->orWhere('scheme_code', 'ilike', "%{$search}%");
                });
            });
        }

        $assignments = $query->paginate($request->get('per_page', 25));

        return response()->json($assignments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'bonus_scheme_id' => 'required|exists:bonus_schemes,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:active,suspended,expired',
            'remarks' => 'nullable|string',
        ]);

        $existing = CustomerBonusScheme::where('customer_id', $validated['customer_id'])
            ->where('bonus_scheme_id', $validated['bonus_scheme_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This customer is already assigned to this bonus scheme',
            ], 422);
        }

        try {
            $assignment = CustomerBonusScheme::create($validated);

            return response()->json([
                'message' => 'Customer bonus scheme assignment created successfully',
                'data' => $assignment->load(['customer', 'bonusScheme']),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $assignment = CustomerBonusScheme::with(['customer', 'bonusScheme', 'usages.salesOrderItem', 'usages.invoiceItem'])
            ->find($id);

        if (!$assignment) {
            return response()->json(['message' => 'Customer bonus scheme assignment not found'], 404);
        }

        return response()->json($assignment);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $assignment = CustomerBonusScheme::find($id);

        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        $validated = $request->validate([
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:active,suspended,expired',
            'remarks' => 'nullable|string',
        ]);

        try {
            $assignment->update($validated);

            return response()->json([
                'message' => 'Assignment updated successfully',
                'data' => $assignment->fresh()->load(['customer', 'bonusScheme']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $assignment = CustomerBonusScheme::find($id);

        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        if ($assignment->usages()->exists()) {
            return response()->json(['message' => 'Cannot delete an assignment with existing usages'], 403);
        }

        try {
            $assignment->delete();

            return response()->json(['message' => 'Assignment deleted successfully']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function suspend($id): JsonResponse
    {
        $assignment = CustomerBonusScheme::find($id);

        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        if ($assignment->status !== 'active') {
            return response()->json(['message' => 'Only active assignments can be suspended'], 403);
        }

        try {
            $assignment->update(['status' => 'suspended']);

            return response()->json([
                'message' => 'Assignment suspended successfully',
                'data' => $assignment->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to suspend assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reactivate($id): JsonResponse
    {
        $assignment = CustomerBonusScheme::find($id);

        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        if ($assignment->status !== 'suspended') {
            return response()->json(['message' => 'Only suspended assignments can be reactivated'], 403);
        }

        try {
            $assignment->update(['status' => 'active']);

            return response()->json([
                'message' => 'Assignment reactivated successfully',
                'data' => $assignment->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to reactivate assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
