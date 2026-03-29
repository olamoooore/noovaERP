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
            $settings = DB::table('company_settings')->first();
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
            'tax_id' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('company_settings')->first();
            $data = [
                'company_name' => $request->company_name,
                'tax_id' => $request->tax_id,
                'address' => $request->address,
                'phone' => $request->phone,
                'email' => $request->email,
                'website' => $request->website,
                'updated_at' => now(),
            ];
            if ($settings) {
                DB::table('company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('company_settings')->insert($data);
            }
            $updated = DB::table('company_settings')->first();
            return response()->json(['success' => true, 'message' => 'Settings updated successfully', 'data' => $updated]);
        } catch (\Exception $e) {
            \Log::error('Failed to update company settings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update settings'], 500);
        }
    }

    public function fiscalYear(): JsonResponse
    {
        try {
            $settings = DB::table('company_settings')->first();
            $month = $settings->fiscal_year_start_month ?? 1;
            $day = $settings->fiscal_year_start_day ?? 1;
            $year = now()->year;
            $startDate = \Carbon\Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
            $fiscalYear = [
                'fiscal_year_start' => $startDate,
                'fiscal_year_end' => $settings->fiscal_year_end ?? \Carbon\Carbon::parse($startDate)->addYear()->subDay()->format('Y-m-d'),
                'lock_date' => $settings->lock_date ?? null,
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
            $settings = DB::table('company_settings')->first();
            $startDate = \Carbon\Carbon::parse($request->fiscal_year_start);
            $data = [
                'fiscal_year_start_month' => $startDate->month,
                'fiscal_year_start_day' => $startDate->day,
                'fiscal_year_end' => $request->fiscal_year_end,
                'lock_date' => $request->lock_date,
                'updated_at' => now(),
            ];
            if ($settings) {
                DB::table('company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('company_settings')->insert($data);
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
            $settings = DB::table('company_settings')->first();
            $currencies = [
                'base_currency' => $settings->base_currency ?? 'USD',
                'date_format' => $settings->date_format ?? 'MM/DD/YYYY',
                'number_format' => $settings->number_format ?? 'en-US',
                'decimal_places' => $settings->decimal_places ?? 2,
                'thousand_separator' => $settings->thousand_separator ?? ',',
                'decimal_separator' => $settings->decimal_separator ?? '.',
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
            $settings = DB::table('company_settings')->first();
            $data = [
                'base_currency' => $request->base_currency,
                'date_format' => $request->date_format,
                'number_format' => $request->number_format,
                'decimal_places' => $request->decimal_places,
                'thousand_separator' => $request->thousand_separator,
                'decimal_separator' => $request->decimal_separator,
                'updated_at' => now(),
            ];
            if ($settings) {
                DB::table('company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('company_settings')->insert($data);
            }
            return response()->json(['success' => true, 'message' => 'Currency settings updated successfully', 'data' => $data]);
        } catch (\Exception $e) {
            \Log::error('Failed to update currency settings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update currency settings'], 500);
        }
    }

    public function accounts(): JsonResponse
    {
        try {
            $settings = DB::table('company_settings')->first();
            $accounts = [
                'accounts_receivable' => $settings->default_receivable_account_id ?? null,
                'accounts_payable' => $settings->default_payable_account_id ?? null,
                'sales_revenue' => $settings->default_revenue_account_id ?? null,
                'sales_tax' => $settings->default_tax_account_id ?? null,
                'purchase_expense' => $settings->default_expense_account_id ?? null,
                'purchase_tax' => $settings->default_purchase_tax_account_id ?? null,
                'inventory' => $settings->default_inventory_account_id ?? null,
                'cost_of_goods_sold' => $settings->default_cogs_account_id ?? null,
                'retained_earnings' => $settings->default_retained_earnings_account_id ?? null,
                'bank_charges' => $settings->default_bank_charges_account_id ?? null,
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
            'purchase_tax' => 'nullable|integer|exists:chart_of_accounts,id',
            'inventory' => 'nullable|integer|exists:chart_of_accounts,id',
            'cost_of_goods_sold' => 'nullable|integer|exists:chart_of_accounts,id',
            'retained_earnings' => 'nullable|integer|exists:chart_of_accounts,id',
            'bank_charges' => 'nullable|integer|exists:chart_of_accounts,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('company_settings')->first();
            $data = [
                'default_receivable_account_id' => $request->accounts_receivable,
                'default_payable_account_id' => $request->accounts_payable,
                'default_revenue_account_id' => $request->sales_revenue,
                'default_expense_account_id' => $request->purchase_expense,
                'default_tax_account_id' => $request->sales_tax,
                'default_purchase_tax_account_id' => $request->purchase_tax,
                'default_inventory_account_id' => $request->inventory,
                'default_cogs_account_id' => $request->cost_of_goods_sold,
                'default_retained_earnings_account_id' => $request->retained_earnings,
                'default_bank_charges_account_id' => $request->bank_charges,
                'updated_at' => now(),
            ];
            if ($settings) {
                DB::table('company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('company_settings')->insert($data);
            }
            return response()->json(['success' => true, 'message' => 'Default accounts updated successfully']);
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
            'tax_id' => null,
            'address' => null,
            'phone' => null,
            'email' => null,
            'website' => null,
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
            'fiscal_year_end' => null,
            'lock_date' => null,
            'base_currency' => 'USD',
            'date_format' => 'MM/DD/YYYY',
            'number_format' => 'en-US',
            'decimal_places' => 2,
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'timezone' => 'UTC',
            'default_receivable_account_id' => null,
            'default_payable_account_id' => null,
            'default_revenue_account_id' => null,
            'default_expense_account_id' => null,
            'default_tax_account_id' => null,
            'default_purchase_tax_account_id' => null,
            'default_inventory_account_id' => null,
            'default_cogs_account_id' => null,
            'default_retained_earnings_account_id' => null,
            'default_bank_charges_account_id' => null,
        ];
    }
}
