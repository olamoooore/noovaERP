<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonusSchemeProduct;
use App\Models\Customer;
use App\Models\CustomerBonusScheme;
use App\Services\CustomerWideSearchService;
use Carbon\Carbon;
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
            // Wide: the customer's full surface (name, contacts, addresses,
            // tags) plus the scheme name/code.
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($customer) => app(CustomerWideSearchService::class)->apply($customer, $search))
                    ->orWhereHas('bonusScheme', function ($q2) use ($search) {
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

        if ($existing && $existing->status === 'active') {
            return response()->json([
                'message' => 'This customer is already assigned to this bonus scheme',
            ], 422);
        }

        // Issue #572 (BS6): only one active assignment may cover a given
        // product at a time — a second scheme overlapping the first is
        // rejected and the conflict is named. Reactivating the same scheme
        // row is excluded by the scheme-id filter, so it reaches the branch
        // below unchanged.
        $conflict = $this->overlappingAssignment($validated);

        if ($conflict) {
            return response()->json(['message' => $this->overlapMessage($conflict)], 422);
        }

        // Issue #190: a suspended/expired assignment could never be re-enrolled
        // — store() blocked on any prior row and the unique(customer_id,
        // bonus_scheme_id) constraint rejects a second one. Reactivate the
        // existing row in place with the new enrolment terms, which preserves
        // the assignment history and keeps the constraint intact.
        if ($existing) {
            try {
                $existing->update([
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'] ?? null,
                    'status' => $validated['status'] ?? 'active',
                    'remarks' => $validated['remarks'] ?? $existing->remarks,
                ]);

                return response()->json([
                    'message' => 'Customer bonus scheme assignment reactivated successfully',
                    'data' => $existing->fresh(['customer', 'bonusScheme']),
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Failed to reactivate assignment',
                    'error' => $e->getMessage(),
                ], 500);
            }
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

        if (! $assignment) {
            return response()->json(['message' => 'Customer bonus scheme assignment not found'], 404);
        }

        return response()->json($assignment);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $assignment = CustomerBonusScheme::find($id);

        if (! $assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        $validated = $request->validate([
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:active,suspended,expired',
            'remarks' => 'nullable|string',
        ]);

        // Issue #572 (BS6): an update must not move an assignment into an
        // overlap with another active scheme covering the same product.
        $conflict = $this->overlappingAssignment([
            'customer_id' => $assignment->customer_id,
            'bonus_scheme_id' => $assignment->bonus_scheme_id,
            'start_date' => $validated['start_date'] ?? $assignment->start_date,
            'end_date' => array_key_exists('end_date', $validated) ? $validated['end_date'] : $assignment->end_date,
            'status' => $validated['status'] ?? $assignment->status,
        ], (int) $assignment->id);

        if ($conflict) {
            return response()->json(['message' => $this->overlapMessage($conflict)], 422);
        }

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

        if (! $assignment) {
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

        if (! $assignment) {
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

        if (! $assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }

        if ($assignment->status !== 'suspended') {
            return response()->json(['message' => 'Only suspended assignments can be reactivated'], 403);
        }

        // Issue #572 (BS6): reactivating must not overlap another active
        // scheme covering the same product.
        $conflict = $this->overlappingAssignment([
            'customer_id' => $assignment->customer_id,
            'bonus_scheme_id' => $assignment->bonus_scheme_id,
            'start_date' => $assignment->start_date,
            'end_date' => $assignment->end_date,
            'status' => 'active',
        ], (int) $assignment->id);

        if ($conflict) {
            return response()->json(['message' => $this->overlapMessage($conflict)], 422);
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

    /**
     * Issue #572 (BS6): one active rule per customer × product at any date.
     * Returns the conflicting assignment when the proposed assignment terms
     * would leave the customer with two active assignments whose date windows
     * overlap and whose schemes cover a common qualifying product.
     * Suspended/expired proposals never stack, so they are not checked.
     */
    private function overlappingAssignment(array $terms, ?int $ignoreId = null): ?CustomerBonusScheme
    {
        if (($terms['status'] ?? 'active') !== 'active') {
            return null;
        }

        $startDate = Carbon::parse($terms['start_date'])->startOfDay();
        $endDate = ! empty($terms['end_date']) ? Carbon::parse($terms['end_date'])->endOfDay() : null;

        $productIds = BonusSchemeProduct::query()
            ->where('bonus_scheme_id', $terms['bonus_scheme_id'])
            ->where(function ($query) {
                $query->where('status', 'active')->orWhereNull('status');
            })
            ->pluck('qualifying_product_id')
            ->unique();

        if ($productIds->isEmpty()) {
            return null;
        }

        $candidates = CustomerBonusScheme::query()
            ->with('bonusScheme')
            ->where('customer_id', $terms['customer_id'])
            ->where('status', 'active')
            ->where('bonus_scheme_id', '!=', $terms['bonus_scheme_id'])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get();

        foreach ($candidates as $candidate) {
            if ($endDate && $candidate->start_date && $candidate->start_date->gt($endDate)) {
                continue;
            }

            if ($candidate->end_date && $candidate->end_date->lt($startDate)) {
                continue;
            }

            $sharesProduct = BonusSchemeProduct::query()
                ->where('bonus_scheme_id', $candidate->bonus_scheme_id)
                ->whereIn('qualifying_product_id', $productIds)
                ->where(function ($query) {
                    $query->where('status', 'active')->orWhereNull('status');
                })
                ->exists();

            if ($sharesProduct) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Issue #572 (BS6): name the scheme an overlapping assignment blocks on.
     */
    private function overlapMessage(CustomerBonusScheme $conflict): string
    {
        $scheme = $conflict->bonusScheme;
        $label = $scheme
            ? "{$scheme->name} ({$scheme->scheme_code})"
            : "scheme #{$conflict->bonus_scheme_id}";

        $window = ($conflict->start_date?->toDateString() ?? 'open start')
            .($conflict->end_date ? ' to '.$conflict->end_date->toDateString() : ' onwards');

        return "This customer already has an overlapping assignment to {$label} ({$window}) covering the same product. End or suspend that assignment before assigning another scheme.";
    }
}
