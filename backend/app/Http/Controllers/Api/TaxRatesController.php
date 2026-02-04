<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Tax Rates Controller
 * Manages tax rates for sales, purchases, VAT, GST, and withholding taxes
 */
class TaxRatesController extends Controller
{
    /**
     * List all tax rates
     */
    public function index(Request $request)
    {
        $query = DB::table('tax_rates')
            ->leftJoin('chart_of_accounts', 'tax_rates.tax_account_id', '=', 'chart_of_accounts.id')
            ->select(
                'tax_rates.*',
                'chart_of_accounts.name as tax_account_name'
            );

        // Filter by type
        if ($request->has('type')) {
            $query->where('tax_rates.type', $request->type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('tax_rates.is_active', $request->is_active);
        }

        // Search by name or code
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('tax_rates.name', 'LIKE', "%{$search}%")
                  ->orWhere('tax_rates.code', 'LIKE', "%{$search}%");
            });
        }

        $taxRates = $query->orderBy('tax_rates.name')->get();

        return response()->json($taxRates);
    }

    /**
     * Get a specific tax rate
     */
    public function show(Request $request, $id)
    {
        $taxRate = DB::table('tax_rates')
            ->where('id', $id)
            ->leftJoin('chart_of_accounts', 'tax_rates.tax_account_id', '=', 'chart_of_accounts.id')
            ->select(
                'tax_rates.*',
                'chart_of_accounts.name as tax_account_name'
            )
            ->first();

        if (!$taxRate) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        return response()->json($taxRate);
    }

    /**
     * Create a new tax rate
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20',
            'rate' => 'required|numeric|min:0|max:100',
            'type' => 'required|in:sales,purchase,vat,gst,withholding',
            'tax_account_id' => 'required|integer|exists:chart_of_accounts,id',
            'is_compound' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if code is unique
        $exists = DB::table('tax_rates')
            ->where('code', $request->code)
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'Tax rate code already exists'
            ], 422);
        }

        $taxRateId = DB::table('tax_rates')->insertGetId([
            'name' => $request->name,
            'code' => $request->code,
            'rate' => $request->rate,
            'type' => $request->type,
            'tax_account_id' => $request->tax_account_id,
            'is_compound' => $request->is_compound ?? false,
            'is_active' => true,
            'description' => $request->description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taxRate = DB::table('tax_rates')->where('id', $taxRateId)->first();

        return response()->json([
            'message' => 'Tax rate created successfully',
            'tax_rate' => $taxRate
        ], 201);
    }

    /**
     * Update a tax rate
     */
    public function update(Request $request, $id)
    {
        $taxRate = DB::table('tax_rates')
            ->where('id', $id)
            ->first();

        if (!$taxRate) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'type' => 'sometimes|in:sales,purchase,vat,gst,withholding',
            'tax_account_id' => 'sometimes|integer|exists:chart_of_accounts,id',
            'is_compound' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if code is unique (excluding current record)
        if ($request->has('code')) {
            $exists = DB::table('tax_rates')
                ->where('code', $request->code)
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'error' => 'Tax rate code already exists'
                ], 422);
            }
        }

        $updateData = array_filter([
            'name' => $request->name,
            'code' => $request->code,
            'rate' => $request->rate,
            'type' => $request->type,
            'tax_account_id' => $request->tax_account_id,
            'is_compound' => $request->is_compound,
            'is_active' => $request->is_active,
            'description' => $request->description,
            'updated_at' => now(),
        ], function($value) {
            return $value !== null;
        });

        DB::table('tax_rates')
            ->where('id', $id)
            ->update($updateData);

        $updatedTaxRate = DB::table('tax_rates')->where('id', $id)->first();

        return response()->json([
            'message' => 'Tax rate updated successfully',
            'tax_rate' => $updatedTaxRate
        ]);
    }

    /**
     * Delete (deactivate) a tax rate
     */
    public function destroy(Request $request, $id)
    {
        $taxRate = DB::table('tax_rates')
            ->where('id', $id)
            ->first();

        if (!$taxRate) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        // Check if tax rate is in use
        $inUse = $this->isTaxRateInUse($id);
        
        if ($inUse) {
            return response()->json([
                'error' => 'Cannot delete tax rate that is in use by transactions'
            ], 422);
        }

        // Soft delete by deactivating
        DB::table('tax_rates')
            ->where('id', $id)
            ->update([
                'is_active' => false,
                'updated_at' => now()
            ]);

        return response()->json([
            'message' => 'Tax rate deactivated successfully'
        ]);
    }

    /**
     * Calculate tax amount
     */
    public function calculateTax(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_rate_id' => 'required|integer|exists:tax_rates,id',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $taxRate = DB::table('tax_rates')
            ->where('id', $request->tax_rate_id)
            ->first();

        if (!$taxRate) {
            return response()->json(['error' => 'Tax rate not found'], 404);
        }

        $taxAmount = ($request->amount * $taxRate->rate) / 100;
        $totalAmount = $request->amount + $taxAmount;

        return response()->json([
            'base_amount' => $request->amount,
            'tax_rate' => $taxRate->rate,
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
        ]);
    }

    /**
     * Check if tax rate is in use
     */
    protected function isTaxRateInUse($taxRateId): bool
    {
        // Check in journal_entry_lines if tax_rate_id column exists
        if (DB::getSchemaBuilder()->hasColumn('journal_entry_lines', 'tax_rate_id')) {
            $inJournalLines = DB::table('journal_entry_lines')
                ->where('tax_rate_id', $taxRateId)
                ->exists();

            if ($inJournalLines) {
                return true;
            }
        }

        // Check in invoices if tax_rate_id column exists
        if (DB::getSchemaBuilder()->hasColumn('invoices', 'tax_rate_id')) {
            $inInvoices = DB::table('invoices')
                ->where('tax_rate_id', $taxRateId)
                ->exists();

            if ($inInvoices) {
                return true;
            }
        }

        return false;
    }
}
