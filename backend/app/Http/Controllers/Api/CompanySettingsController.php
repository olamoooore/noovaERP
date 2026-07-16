<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CompanySettingsController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $settings = DB::table('company_settings')->first();
            if (! $settings) {
                DB::table('company_settings')->insert([
                    'base_currency' => 'SGD',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $settings = DB::table('company_settings')->first();
            }
            $settings = $this->hydrateStructuredFields($settings);

            return response()->json(['success' => true, 'data' => $settings]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch company settings', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to fetch settings: '.$e->getMessage()], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'nullable|string|max:255',
            'company_logo_url' => 'nullable|url|max:500',
            'tax_id' => 'nullable|string|max:50',
            'gst_registration_number' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:1000',
            'addresses' => 'nullable|array',
            'addresses.*' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:30',
            'phone_numbers' => 'nullable|array',
            'phone_numbers.*' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:500',
            'social_links' => 'nullable|array',
            'social_links.facebook' => 'nullable|string|max:500',
            'social_links.instagram' => 'nullable|string|max:500',
            'social_links.x' => 'nullable|string|max:500',
            'social_links.linkedin' => 'nullable|string|max:500',
            'social_links.tiktok' => 'nullable|string|max:500',
            'social_links.youtube' => 'nullable|string|max:500',
            'social_links.whatsapp' => 'nullable|string|max:500',
            'social_links.snapchat' => 'nullable|string|max:500',
            'social_links.telegram' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            $settings = DB::table('company_settings')->first();
            $data = ['updated_at' => now()];

            foreach (['company_name', 'company_logo_url', 'tax_id', 'gst_registration_number', 'address', 'phone', 'email', 'website'] as $field) {
                if ($request->has($field)) {
                    $data[$field] = $request->input($field);
                }
            }

            if ($request->has('phone_numbers')) {
                $phones = collect($request->input('phone_numbers', []))
                    ->map(fn ($item) => trim((string) $item))
                    ->filter(fn ($item) => $item !== '')
                    ->values()
                    ->all();

                $data['phone_numbers'] = empty($phones) ? null : json_encode($phones);
                if (! isset($data['phone']) && ! empty($phones)) {
                    $data['phone'] = $phones[0];
                }
            }

            if ($request->has('addresses')) {
                $addresses = collect($request->input('addresses', []))
                    ->map(fn ($item) => trim((string) $item))
                    ->filter(fn ($item) => $item !== '')
                    ->values()
                    ->all();

                $data['addresses'] = empty($addresses) ? null : json_encode($addresses);
                if (! isset($data['address']) && ! empty($addresses)) {
                    $data['address'] = $addresses[0];
                }
            }

            if ($request->has('social_links')) {
                $socialLinks = collect((array) $request->input('social_links', []))
                    ->mapWithKeys(fn ($value, $key) => [$key => trim((string) $value)])
                    ->filter(fn ($value) => $value !== '')
                    ->all();

                $data['social_links'] = empty($socialLinks) ? null : json_encode($socialLinks);
            }

            if (! isset($data['gst_registration_number']) && $request->has('tax_id')) {
                $data['gst_registration_number'] = $request->input('tax_id');
            }
            if (! isset($data['tax_id']) && $request->has('gst_registration_number')) {
                $data['tax_id'] = $request->input('gst_registration_number');
            }

            if ($settings) {
                DB::table('company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('company_settings')->insert($data);
            }
            $updated = $this->hydrateStructuredFields(DB::table('company_settings')->first());

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

            if (! $settings) {
                DB::table('company_settings')->insert([
                    'base_currency' => 'SGD',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $settings = DB::table('company_settings')->first();
            }

            $currencies = [
                'base_currency' => $settings->base_currency ?? 'SGD',
                'date_format' => $settings->date_format ?? 'MM/DD/YYYY',
                'number_format' => $settings->number_format ?? 'en-US',
                'decimal_places' => $settings->decimal_places ?? 2,
                'thousand_separator' => $settings->thousand_separator ?? ',',
                'decimal_separator' => $settings->decimal_separator ?? '.',
            ];

            // Ensure at least default currencies exist
            $currencyCount = DB::table('currencies')->count();
            if ($currencyCount === 0) {
                DB::table('currencies')->insert([
                    ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                    ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                    ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                    ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ]);
            }

            // Include available active currencies from the shared registry
            $activeCurrencies = DB::table('currencies')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name', 'symbol', 'is_active']);

            return response()->json([
                'success' => true,
                'data' => $currencies,
                'available_currencies' => $activeCurrencies,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch currency settings', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to fetch currency settings: '.$e->getMessage()], 500);
        }
    }

    public function updateCurrencies(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'base_currency' => 'required|string|size:3|exists:currencies,code',
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

            return response()->json(['success' => false, 'message' => 'Failed to update currency settings: '.$e->getMessage()], 500);
        }
    }

    public function accounts(): JsonResponse
    {
        try {
            $settings = DB::table('company_settings')->first();

            if (! $settings) {
                // Auto-create a default row so the frontend has something to bind to
                DB::table('company_settings')->insert([
                    'base_currency' => 'SGD',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $settings = DB::table('company_settings')->first();
            }

            $accounts = [
                'accounts_receivable' => $settings->default_receivable_account_id ?? null,
                'accounts_payable' => $settings->default_payable_account_id ?? null,
                'sales_revenue' => $settings->default_revenue_account_id ?? null,
                'shipping_revenue' => $settings->shipping_revenue_account_id ?? null,
                'sales_tax' => $settings->default_tax_account_id ?? null,
                'purchase_expense' => $settings->default_expense_account_id ?? null,
                'purchase_tax' => $settings->default_purchase_tax_account_id ?? null,
                'inventory' => $settings->default_inventory_account_id ?? null,
                'cost_of_goods_sold' => $settings->default_cogs_account_id ?? null,
                'retained_earnings' => $settings->default_retained_earnings_account_id ?? null,
                'bank_charges' => $settings->default_bank_charges_account_id ?? null,
            ];

            // Defensive: null out extended fields if the DB columns don't exist yet
            $extendedColumns = ['shipping_revenue', 'purchase_tax', 'inventory', 'cost_of_goods_sold', 'retained_earnings', 'bank_charges'];
            foreach ($extendedColumns as $key) {
                $dbColumn = match ($key) {
                    'shipping_revenue' => 'shipping_revenue_account_id',
                    'purchase_tax' => 'default_purchase_tax_account_id',
                    'inventory' => 'default_inventory_account_id',
                    'cost_of_goods_sold' => 'default_cogs_account_id',
                    'retained_earnings' => 'default_retained_earnings_account_id',
                    'bank_charges' => 'default_bank_charges_account_id',
                };
                if (! Schema::hasColumn('company_settings', $dbColumn)) {
                    $accounts[$key] = null;
                }
            }

            return response()->json(['success' => true, 'data' => $accounts]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch default accounts', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to fetch default accounts: '.$e->getMessage()], 500);
        }
    }

    public function updateAccounts(Request $request): JsonResponse
    {
        // Normalize empty/blank/0 values to null before validation
        $normalize = function ($value) {
            if ($value === '' || $value === '0' || $value === 0) {
                return null;
            }

            return $value;
        };

        $input = collect($request->all())->map($normalize)->all();

        $validator = Validator::make($input, [
            'accounts_receivable' => 'nullable|integer|exists:chart_of_accounts,id',
            'accounts_payable' => 'nullable|integer|exists:chart_of_accounts,id',
            'sales_revenue' => 'nullable|integer|exists:chart_of_accounts,id',
            'shipping_revenue' => 'nullable|integer|exists:chart_of_accounts,id',
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
                'default_receivable_account_id' => $input['accounts_receivable'] ?? null,
                'default_payable_account_id' => $input['accounts_payable'] ?? null,
                'default_revenue_account_id' => $input['sales_revenue'] ?? null,
                'default_expense_account_id' => $input['purchase_expense'] ?? null,
                'default_tax_account_id' => $input['sales_tax'] ?? null,
                'updated_at' => now(),
            ];

            // Defensive: only include extended default-account columns if they exist in DB.
            // Some tenants may not have run the migration that added these columns.
            $extendedColumns = [
                'shipping_revenue_account_id' => $input['shipping_revenue'] ?? null,
                'default_purchase_tax_account_id' => $input['purchase_tax'] ?? null,
                'default_inventory_account_id' => $input['inventory'] ?? null,
                'default_cogs_account_id' => $input['cost_of_goods_sold'] ?? null,
                'default_retained_earnings_account_id' => $input['retained_earnings'] ?? null,
                'default_bank_charges_account_id' => $input['bank_charges'] ?? null,
            ];

            foreach ($extendedColumns as $column => $value) {
                $exists = DB::selectOne("
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                    AND table_name = 'company_settings'
                    AND column_name = ?
                ", [$column]);
                if ($exists) {
                    $data[$column] = $value;
                }
            }

            if ($settings) {
                DB::table('company_settings')->update($data);
            } else {
                $data['created_at'] = now();
                $data['company_name'] = $request->company_name ?? null;
                $data['base_currency'] = $request->base_currency ?? 'SGD';
                DB::table('company_settings')->insert($data);
            }

            $fresh = DB::table('company_settings')->first();

            return response()->json([
                'success' => true,
                'message' => 'Default accounts updated successfully',
                'data' => [
                    'accounts_receivable' => $fresh->default_receivable_account_id ?? null,
                    'accounts_payable' => $fresh->default_payable_account_id ?? null,
                    'sales_revenue' => $fresh->default_revenue_account_id ?? null,
                    'shipping_revenue' => $fresh->shipping_revenue_account_id ?? null,
                    'sales_tax' => $fresh->default_tax_account_id ?? null,
                    'purchase_expense' => $fresh->default_expense_account_id ?? null,
                    'purchase_tax' => $fresh->default_purchase_tax_account_id ?? null,
                    'inventory' => $fresh->default_inventory_account_id ?? null,
                    'cost_of_goods_sold' => $fresh->default_cogs_account_id ?? null,
                    'retained_earnings' => $fresh->default_retained_earnings_account_id ?? null,
                    'bank_charges' => $fresh->default_bank_charges_account_id ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to update default accounts', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to update default accounts: '.$e->getMessage()], 500);
        }
    }

    private function getDefaultSettings()
    {
        return (object) [
            'company_name' => null,
            'company_logo_url' => null,
            'tax_id' => null,
            'gst_registration_number' => null,
            'address' => null,
            'addresses' => [],
            'phone' => null,
            'phone_numbers' => [],
            'email' => null,
            'website' => null,
            'social_links' => [],
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
            'fiscal_year_end' => null,
            'lock_date' => null,
            'base_currency' => 'SGD',
            'date_format' => 'MM/DD/YYYY',
            'number_format' => 'en-US',
            'decimal_places' => 2,
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'timezone' => 'UTC',
            'default_receivable_account_id' => null,
            'default_payable_account_id' => null,
            'default_revenue_account_id' => null,
            'shipping_revenue_account_id' => null,
            'default_expense_account_id' => null,
            'default_tax_account_id' => null,
            'default_purchase_tax_account_id' => null,
            'default_inventory_account_id' => null,
            'default_cogs_account_id' => null,
            'default_retained_earnings_account_id' => null,
            'default_bank_charges_account_id' => null,
        ];
    }

    private function hydrateStructuredFields(object $settings): object
    {
        $settings->phone_numbers = $this->decodeJsonArray(
            $settings->phone_numbers ?? null,
            ! empty($settings->phone) ? [$settings->phone] : []
        );

        $settings->addresses = $this->decodeJsonArray(
            $settings->addresses ?? null,
            ! empty($settings->address) ? [$settings->address] : []
        );

        $settings->social_links = $this->decodeJsonObject($settings->social_links ?? null, []);
        $settings->gst_registration_number = $settings->gst_registration_number ?? $settings->tax_id ?? null;

        return $settings;
    }

    private function decodeJsonArray(mixed $value, array $fallback = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values($decoded);
            }
        }

        return $fallback;
    }

    private function decodeJsonObject(mixed $value, array $fallback = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return $fallback;
    }
}
