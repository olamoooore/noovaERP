<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CompanySettingsController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $settings = DB::table('public.company_settings')->first();
            if (!$settings) {
                $settings = $this->getDefaultSettings();
            }
            return response()->json(['success' => true, 'data' => $settings]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch company settings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to fetch settings'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('public.company_settings')->first();
            $data = ['company_name' => $request->company_name, 'updated_at' => now()];
            if ($settings) {
                DB::table('public.company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('public.company_settings')->insert($data);
            }
            $updated = DB::table('public.company_settings')->first();
            return response()->json(['success' => true, 'message' => 'Settings updated successfully', 'data' => $updated]);
        } catch (\Exception $e) {
            \Log::error('Failed to update company settings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update settings'], 500);
        }
    }

    public function fiscalYear(): JsonResponse
    {
        try {
            $settings = DB::table('public.company_settings')->first();
            $fiscalYear = [
                'fiscal_year_start' => ($settings->fiscal_year_start_month ?? 1) . '-' . ($settings->fiscal_year_start_day ?? 1),
                'fiscal_year_end' => null,
                'lock_date' => null,
            ];
            return response()->json(['success' => true, 'data' => $fiscalYear]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch fiscal year settings'], 500);
        }
    }

    public function updateFiscalYear(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fiscal_year_start' => 'required|date',
            'fiscal_year_end' => 'required|date|after:fiscal_year_start',
            'lock_date' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('public.company_settings')->first();
            $startDate = \Carbon\Carbon::parse($request->fiscal_year_start);
            $data = ['fiscal_year_start_month' => $startDate->month, 'fiscal_year_start_day' => $startDate->day, 'updated_at' => now()];
            if ($settings) {
                DB::table('public.company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('public.company_settings')->insert($data);
            }
            return response()->json(['success' => true, 'message' => 'Fiscal year settings updated successfully', 'data' => ['fiscal_year_start' => $request->fiscal_year_start, 'fiscal_year_end' => $request->fiscal_year_end, 'lock_date' => $request->lock_date]]);
        } catch (\Exception $e) {
            \Log::error('Failed to update fiscal year settings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update fiscal year settings'], 500);
        }
    }

    public function currencies(): JsonResponse
    {
        try {
            $settings = DB::table('public.company_settings')->first();
            $currencies = [
                'base_currency' => $settings->base_currency ?? 'USD',
                'date_format' => $settings->date_format ?? 'MM/DD/YYYY',
                'number_format' => $settings->number_format ?? 'en-US',
                'decimal_places' => 2,
                'thousand_separator' => ',',
                'decimal_separator' => '.',
            ];
            return response()->json(['success' => true, 'data' => $currencies]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch currency settings'], 500);
        }
    }

    public function updateCurrencies(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'base_currency' => 'required|string|size:3',
            'date_format' => 'required|string',
            'number_format' => 'required|string',
            'decimal_places' => 'required|integer|min:0|max:4',
            'thousand_separator' => 'required|string|max:1',
            'decimal_separator' => 'required|string|max:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('public.company_settings')->first();
            $data = ['base_currency' => $request->base_currency, 'date_format' => $request->date_format, 'number_format' => $request->number_format, 'updated_at' => now()];
            if ($settings) {
                DB::table('public.company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('public.company_settings')->insert($data);
            }
            return response()->json(['success' => true, 'message' => 'Currency settings updated successfully', 'data' => ['base_currency' => $request->base_currency, 'date_format' => $request->date_format, 'number_format' => $request->number_format, 'decimal_places' => $request->decimal_places, 'thousand_separator' => $request->thousand_separator, 'decimal_separator' => $request->decimal_separator]]);
        } catch (\Exception $e) {
            \Log::error('Failed to update currency settings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update currency settings'], 500);
        }
    }

    public function accounts(): JsonResponse
    {
        try {
            $settings = DB::table('public.company_settings')->first();
            $accounts = [
                'accounts_receivable' => $settings->default_receivable_account_id ?? null,
                'accounts_payable' => $settings->default_payable_account_id ?? null,
                'sales_revenue' => $settings->default_revenue_account_id ?? null,
                'sales_tax' => $settings->default_tax_account_id ?? null,
                'purchase_expense' => $settings->default_expense_account_id ?? null,
                'purchase_tax' => null,
                'inventory' => null,
                'cost_of_goods_sold' => null,
                'retained_earnings' => null,
                'bank_charges' => null,
            ];
            return response()->json(['success' => true, 'data' => $accounts]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch default accounts'], 500);
        }
    }

    public function updateAccounts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'accounts_receivable' => 'nullable|integer|exists:chart_of_accounts,id',
            'accounts_payable' => 'nullable|integer|exists:chart_of_accounts,id',
            'sales_revenue' => 'nullable|integer|exists:chart_of_accounts,id',
            'sales_tax' => 'nullable|integer|exists:chart_of_accounts,id',
            'purchase_expense' => 'nullable|integer|exists:chart_of_accounts,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('public.company_settings')->first();
            $data = [
                'default_receivable_account_id' => $request->accounts_receivable,
                'default_payable_account_id' => $request->accounts_payable,
                'default_revenue_account_id' => $request->sales_revenue,
                'default_expense_account_id' => $request->purchase_expense,
                'default_tax_account_id' => $request->sales_tax,
                'updated_at' => now(),
            ];
            if ($settings) {
                DB::table('public.company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('public.company_settings')->insert($data);
            }
            return response()->json(['success' => true, 'message' => 'Default accounts updated successfully', 'data' => ['accounts_receivable' => $request->accounts_receivable, 'accounts_payable' => $request->accounts_payable, 'sales_revenue' => $request->sales_revenue, 'sales_tax' => $request->sales_tax, 'purchase_expense' => $request->purchase_expense]]);
        } catch (\Exception $e) {
            \Log::error('Failed to update default accounts', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update default accounts'], 500);
        }
    }

    private function getDefaultSettings()
    {
        return (object) [
            'company_name' => null,
            'company_logo_url' => null,
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
            'base_currency' => 'USD',
            'date_format' => 'MM/DD/YYYY',
            'number_format' => 'en-US',
            'timezone' => 'UTC',
            'default_receivable_account_id' => null,
            'default_payable_account_id' => null,
            'default_revenue_account_id' => null,
            'default_expense_account_id' => null,
            'default_tax_account_id' => null,
        ];
    }
}
