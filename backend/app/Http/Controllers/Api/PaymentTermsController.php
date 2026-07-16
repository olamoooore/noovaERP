<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Payment Terms Controller
 * Manages payment terms for invoices and bills
 */
class PaymentTermsController extends Controller
{
    /**
     * List all payment terms
     */
    public function index(Request $request)
    {
        $query = PaymentTerm::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search by name or code
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%");
            });
        }

        $terms = $query->orderBy('name')->get();

        // Add usage count for each term
        $terms->each(function ($term) {
            $term->usage_count = $this->getUsageCount($term->id);
        });

        return response()->json($terms);
    }

    /**
     * Get a specific payment term
     */
    public function show($id)
    {
        $term = PaymentTerm::findOrFail($id);
        $term->usage_count = $this->getUsageCount($id);

        return response()->json($term);
    }

    /**
     * Create a new payment term
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:payment_terms,code',
            'type' => 'required|in:net_days,end_of_month,immediate',
            'days' => 'required|integer|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_days' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Validate discount configuration
        if ($request->discount_percentage && ! $request->discount_days) {
            return response()->json([
                'error' => 'Discount days required when discount percentage is set',
            ], 422);
        }

        if ($request->discount_days && ! $request->discount_percentage) {
            return response()->json([
                'error' => 'Discount percentage required when discount days is set',
            ], 422);
        }

        // If setting as default, unset other defaults
        if ($request->is_default) {
            PaymentTerm::where('is_default', true)->update(['is_default' => false]);
        }

        $term = PaymentTerm::create([
            'name' => $request->name,
            'code' => $request->code,
            'type' => $request->type,
            'days' => $request->days,
            'discount_percentage' => $request->discount_percentage,
            'discount_days' => $request->discount_days,
            'description' => $request->description,
            'is_default' => $request->is_default ?? false,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Payment term created successfully',
            'term' => $term,
        ], 201);
    }

    /**
     * Update a payment term
     */
    public function update(Request $request, $id)
    {
        $term = PaymentTerm::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:payment_terms,code,'.$id,
            'type' => 'sometimes|in:net_days,end_of_month,immediate',
            'days' => 'sometimes|integer|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_days' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Validate discount configuration
        $discountPercentage = $request->has('discount_percentage')
            ? $request->discount_percentage
            : $term->discount_percentage;
        $discountDays = $request->has('discount_days')
            ? $request->discount_days
            : $term->discount_days;

        if ($discountPercentage && ! $discountDays) {
            return response()->json([
                'error' => 'Discount days required when discount percentage is set',
            ], 422);
        }

        if ($discountDays && ! $discountPercentage) {
            return response()->json([
                'error' => 'Discount percentage required when discount days is set',
            ], 422);
        }

        // If setting as default, unset other defaults
        if ($request->has('is_default') && $request->is_default) {
            PaymentTerm::where('id', '!=', $id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $term->update($request->only([
            'name', 'code', 'type', 'days', 'discount_percentage',
            'discount_days', 'description', 'is_default', 'is_active',
        ]));

        return response()->json([
            'message' => 'Payment term updated successfully',
            'term' => $term->fresh(),
        ]);
    }

    /**
     * Delete (deactivate) a payment term
     */
    public function destroy($id)
    {
        $term = PaymentTerm::findOrFail($id);

        // Check if term is in use
        $usageCount = $this->getUsageCount($id);
        if ($usageCount > 0) {
            return response()->json([
                'error' => "Cannot delete payment term that is in use by {$usageCount} customer(s)/vendor(s)",
            ], 422);
        }

        $term->update(['is_active' => false]);

        return response()->json([
            'message' => 'Payment term deactivated successfully',
        ]);
    }

    /**
     * Calculate due date for a payment term
     */
    public function calculateDueDate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_term_id' => 'required|exists:payment_terms,id',
            'invoice_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $term = PaymentTerm::findOrFail($request->payment_term_id);
        $dueDate = $term->calculateDueDate($request->invoice_date);
        $discountDueDate = $term->calculateDiscountDueDate($request->invoice_date);

        return response()->json([
            'due_date' => $dueDate->format('Y-m-d'),
            'discount_due_date' => $discountDueDate ? $discountDueDate->format('Y-m-d') : null,
            'discount_percentage' => $term->discount_percentage,
        ]);
    }

    /**
     * Get default payment term
     */
    public function getDefault()
    {
        $term = PaymentTerm::getDefault();

        if (! $term) {
            return response()->json([
                'error' => 'No default payment term configured',
            ], 404);
        }

        return response()->json($term);
    }

    /**
     * Set a payment term as default
     */
    public function setDefault($id)
    {
        $term = PaymentTerm::findOrFail($id);

        if (! $term->is_active) {
            return response()->json([
                'error' => 'Cannot set inactive payment term as default',
            ], 422);
        }

        // Unset other defaults
        PaymentTerm::where('id', '!=', $id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $term->update(['is_default' => true]);

        return response()->json([
            'message' => 'Payment term set as default successfully',
            'term' => $term,
        ]);
    }

    /**
     * Get usage count for a payment term
     */
    protected function getUsageCount($termId): int
    {
        $count = 0;

        // Check customers table
        if (DB::getSchemaBuilder()->hasTable('customers')) {
            $count += DB::table('customers')
                ->where('payment_terms', $termId)
                ->count();
        }

        // Check vendors table
        if (DB::getSchemaBuilder()->hasTable('vendors')) {
            $count += DB::table('vendors')
                ->where('payment_terms', $termId)
                ->count();
        }

        return $count;
    }
}
