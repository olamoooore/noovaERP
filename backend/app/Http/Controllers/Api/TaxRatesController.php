<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * G-17 (#274): legacy `/accounting/tax-rates` compatibility shim.
 *
 * The `tax_rates` TABLE is retired as an application data source — this
 * controller now reads/writes the canonical `taxes` engine store while
 * preserving the legacy response contract for the existing configuration UI
 * (tax_account_id / is_compound / description are accepted but ignored; GL
 * mapping uses AccountMapping keys, not per-rule accounts).
 *
 * The table itself (and its one-shot `taxes:migrate-legacy-rates` migration
 * command + AI whitelist entry) remains until #267 sanctions the backfill and
 * drop.
 */
class TaxRatesController extends Controller
{
    public function index(Request $request)
    {
        $query = Tax::query();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('name')->get()->map(fn (Tax $tax) => $this->legacyShape($tax))->all()
        );
    }

    public function show(Request $request, $id)
    {
        $tax = Tax::find($id);

        if (! $tax) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        return response()->json($this->legacyShape($tax));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20',
            'rate' => 'required|numeric|min:0|max:100',
            'type' => 'required|in:sales,purchase,vat,gst,withholding,service,other',
            'tax_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'is_compound' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (Tax::where('code', $request->code)->exists()) {
            return response()->json(['error' => 'Tax rate code already exists'], 422);
        }

        $rate = (float) $request->rate;

        $tax = Tax::create([
            'name' => $request->name,
            'code' => $request->code,
            'rate' => $rate,
            'type' => $request->type,
            'scope' => 'both',
            'treatment' => $rate > 0 ? 'standard_rated' : 'zero_rated',
            'is_active' => true,
        ])->refresh();

        return response()->json([
            'message' => 'Tax rate created successfully',
            'tax_rate' => $this->legacyShape($tax),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tax = Tax::find($id);

        if (! $tax) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'type' => 'sometimes|in:sales,purchase,vat,gst,withholding,service,other',
            'tax_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'is_compound' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('code') && Tax::where('code', $request->code)->where('id', '!=', $tax->id)->exists()) {
            return response()->json(['error' => 'Tax rate code already exists'], 422);
        }

        $updates = array_filter([
            'name' => $request->name,
            'code' => $request->code,
            'rate' => $request->has('rate') ? (float) $request->rate : null,
            'type' => $request->type,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ], fn ($value) => $value !== null);

        if ($updates !== []) {
            $tax->update($updates);
            $tax->increment('version');
            $tax->refresh();
        }

        return response()->json([
            'message' => 'Tax rate updated successfully',
            'tax_rate' => $this->legacyShape($tax),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tax = Tax::find($id);

        if (! $tax) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        if ($this->isTaxRateInUse((int) $tax->id)) {
            return response()->json([
                'error' => 'Cannot delete tax rate that is in use by transactions',
            ], 422);
        }

        // Legacy contract: deactivate rather than hard-delete.
        $tax->update(['is_active' => false]);
        $tax->increment('version');

        return response()->json(['message' => 'Tax rate deactivated successfully']);
    }

    public function calculateTax(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_rate_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tax = Tax::find($request->tax_rate_id);

        if (! $tax) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        // MRD R3.1: engine-owned arithmetic.
        $calc = \App\Services\TaxEngineService::calculate((float) $request->amount, (float) $tax->rate, (bool) $tax->is_inclusive);

        return response()->json([
            'base_amount' => $request->amount,
            'tax_rate' => (float) $tax->rate,
            'tax_amount' => round($calc['tax'], 2),
            'total_amount' => round($calc['gross'], 2),
        ]);
    }

    /**
     * Legacy response contract (fields the existing UI reads).
     */
    private function legacyShape(Tax $tax): array
    {
        return [
            'id' => (int) $tax->id,
            'name' => $tax->name,
            'code' => $tax->code,
            'rate' => (float) $tax->rate,
            'type' => $tax->type,
            'tax_account_id' => null,
            'tax_account_name' => null,
            'is_compound' => false,
            'is_active' => (bool) $tax->is_active,
            'description' => null,
            'created_at' => $tax->created_at,
            'updated_at' => $tax->updated_at,
        ];
    }

    protected function isTaxRateInUse(int $taxRateId): bool
    {
        if (DB::getSchemaBuilder()->hasColumn('journal_entry_lines', 'tax_id')) {
            return DB::table('journal_entry_lines')->where('tax_id', $taxRateId)->exists();
        }

        return false;
    }
}
