<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AccountingPoliciesController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $policies = DB::table('public.accounting_policies')->first();
            if (!$policies) {
                $policies = $this->getDefaultPolicies();
            }
            return response()->json(['success' => true, 'data' => $policies]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch accounting policies', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to fetch policies'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'depreciation_method' => 'nullable|string|in:straight_line,declining_balance,units_of_production,sum_of_years_digits',
            'inventory_valuation_method' => 'nullable|string|in:fifo,lifo,weighted_average,specific_identification',
            'revenue_recognition_policy' => 'nullable|string|in:accrual,cash,percentage_completion,completed_contract',
            'expense_recognition_policy' => 'nullable|string|in:matching,immediate,systematic',
            'bad_debt_policy' => 'nullable|string|in:allowance_method,direct_writeoff,aging_analysis',
            'capitalization_threshold' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $policies = DB::table('public.accounting_policies')->first();
            
            $data = array_filter([
                'depreciation_method' => $request->depreciation_method,
                'inventory_valuation_method' => $request->inventory_valuation_method,
                'revenue_recognition_policy' => $request->revenue_recognition_policy,
                'expense_recognition_policy' => $request->expense_recognition_policy,
                'bad_debt_policy' => $request->bad_debt_policy,
                'capitalization_threshold' => $request->capitalization_threshold,
                'notes' => $request->notes,
                'updated_at' => now(),
            ], function ($value) {
                return $value !== null;
            });

            if ($policies) {
                DB::table('public.accounting_policies')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('public.accounting_policies')->insert($data);
            }

            $updated = DB::table('public.accounting_policies')->first();
            return response()->json([
                'success' => true,
                'message' => 'Accounting policies updated successfully',
                'data' => $updated
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to update accounting policies', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update policies'], 500);
        }
    }

    private function getDefaultPolicies()
    {
        return (object) [
            'id' => null,
            'depreciation_method' => 'straight_line',
            'inventory_valuation_method' => 'fifo',
            'revenue_recognition_policy' => 'accrual',
            'expense_recognition_policy' => 'matching',
            'bad_debt_policy' => 'allowance_method',
            'capitalization_threshold' => 1000.00,
            'notes' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}
