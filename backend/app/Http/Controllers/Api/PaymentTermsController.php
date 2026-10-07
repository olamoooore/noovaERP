<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTerm;
use App\Models\PaymentTermLine;
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

        $terms = $query->with('lines')->orderBy('name')->get();

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
        $term = PaymentTerm::with('lines')->findOrFail($id);
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
        ] + self::instalmentRules());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($error = $this->instalmentError($request->input('lines'))) {
            return response()->json(['errors' => ['lines' => [$error]]], 422);
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

        $term = DB::transaction(function () use ($request) {
            $term = PaymentTerm::create([
                'name' => $request->name,
                'code' => $request->code,
                'type' => $request->type,
                'days' => $request->days,
                // The columns are NOT NULL DEFAULT 0 — an omitted discount
                // is "no discount", not NULL.
                'discount_percentage' => $request->discount_percentage ?? 0,
                'discount_days' => $request->discount_days ?? 0,
                'description' => $request->description,
                'is_default' => $request->is_default ?? false,
                'is_active' => true,
            ]);

            $this->syncInstalments($term, $request->input('lines'));

            return $term;
        });

        return response()->json([
            'message' => 'Payment term created successfully',
            'term' => $term->load('lines'),
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
        ] + self::instalmentRules());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($error = $this->instalmentError($request->input('lines'))) {
            return response()->json(['errors' => ['lines' => [$error]]], 422);
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

        DB::transaction(function () use ($request, $term) {
            $term->update($request->only([
                'name', 'code', 'type', 'days', 'discount_percentage',
                'discount_days', 'description', 'is_default', 'is_active',
            ]));

            // Issue #324: `lines` replaces the instalments when sent ([] clears
            // them); omitted keeps the current ones. Issued invoices keep
            // their own schedule snapshot.
            if ($request->has('lines')) {
                $this->syncInstalments($term, $request->input('lines') ?? []);
            }
        });

        return response()->json([
            'message' => 'Payment term updated successfully',
            'term' => $term->fresh('lines'),
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

        $term = PaymentTerm::with('lines')->findOrFail($request->payment_term_id);
        $dueDate = $term->calculateDueDate($request->invoice_date);
        $discountDueDate = $term->calculateDiscountDueDate($request->invoice_date);

        return response()->json([
            'due_date' => $dueDate->format('Y-m-d'),
            'discount_due_date' => $discountDueDate ? $discountDueDate->format('Y-m-d') : null,
            'discount_percentage' => $term->discount_percentage,
            // Issue #324: the instalment schedule (empty = single due date).
            'instalments' => $term->instalmentSchedule($request->invoice_date),
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
     * Issue #324: validation rules for the optional instalment lines.
     *
     * @return array<string, mixed>
     */
    private static function instalmentRules(): array
    {
        return [
            'lines' => 'nullable|array|max:24',
            'lines.*.value_type' => 'required|in:'.implode(',', PaymentTermLine::VALUE_TYPES),
            'lines.*.value' => 'nullable|numeric|min:0|max:100',
            'lines.*.days' => 'required|integer|min:0|max:3650',
            'lines.*.delay_type' => 'nullable|in:'.implode(',', PaymentTermLine::DELAY_TYPES),
        ];
    }

    /**
     * Instalments must cover exactly 100% of the document: at most one
     * balance line, percentages that do not exceed 100, and — without a
     * balance line — percentages that add up to exactly 100.
     */
    private function instalmentError(mixed $lines): ?string
    {
        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $balanceLines = 0;
        $percentTotal = 0.0;
        foreach ($lines as $line) {
            if (($line['value_type'] ?? null) === PaymentTermLine::VALUE_BALANCE) {
                $balanceLines++;

                continue;
            }

            $value = (float) ($line['value'] ?? 0);
            if ($value <= 0) {
                return 'Each percentage instalment needs a value above 0.';
            }
            $percentTotal += $value;
        }

        if ($balanceLines > 1) {
            return 'Only one instalment can take the balance.';
        }

        $percentTotal = round($percentTotal, 4);
        if ($percentTotal > 100) {
            return 'Instalment percentages cannot exceed 100%.';
        }

        if ($balanceLines === 0 && abs($percentTotal - 100) > 0.0001) {
            return 'Instalment percentages must add up to 100% (or add a balance line).';
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    private function syncInstalments(PaymentTerm $term, ?array $lines): void
    {
        $term->lines()->delete();

        foreach (array_values($lines ?? []) as $index => $line) {
            $isBalance = ($line['value_type'] ?? null) === PaymentTermLine::VALUE_BALANCE;

            $term->lines()->create([
                'sequence' => $index + 1,
                'value_type' => $isBalance ? PaymentTermLine::VALUE_BALANCE : PaymentTermLine::VALUE_PERCENT,
                'value' => $isBalance ? 0 : (float) ($line['value'] ?? 0),
                'days' => (int) ($line['days'] ?? 0),
                'delay_type' => $line['delay_type'] ?? 'net_days',
            ]);
        }
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

            // Issue #324: customers that default to this master term.
            if (DB::getSchemaBuilder()->hasColumn('customers', 'payment_term_id')) {
                $count += DB::table('customers')
                    ->where('payment_term_id', $termId)
                    ->count();
            }
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
