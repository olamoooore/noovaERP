<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Services\AccountMappingService;
use App\Accounting\Services\AccountRoleResolver;
use App\Http\Controllers\Controller;
use App\Models\AccountMapping;
use App\Models\FiscalPeriod;
use App\Services\CashRoundingService;
use App\Support\TenantUserHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CompanySettingsController extends Controller
{
    /**
     * #877 (SA3): the Default Accounts screen fields and the account-role
     * mapping keys posting actually resolves through AccountMappingService.
     * This screen used to write company_settings.default_* columns that
     * nothing on the posting path read.
     */
    private const DEFAULT_ACCOUNT_MAPPINGS = [
        'accounts_receivable' => 'accounts_receivable',
        'accounts_payable' => 'accounts_payable',
        'bank_account' => 'bank',
        'sales_revenue' => 'sales_revenue',
        'shipping_revenue' => 'shipping_revenue',
        'sales_tax' => 'sales_tax_payable',
        'purchase_expense' => 'general_expense',
        'purchase_tax' => 'input_tax',
        'inventory' => 'inventory',
        'cost_of_goods_sold' => 'cogs',
        'retained_earnings' => 'retained_earnings',
        'bank_charges' => 'bank_charges',
    ];

    /**
     * #885 (UX3): screen fields posting can resolve per legal entity through
     * entity_account_roles (AccountRoleResolver priority 1). Assigning one of
     * these for a selected entity overrides the tenant-wide mapping for that
     * entity only; fields without a role stay tenant-wide.
     */
    private const DEFAULT_ACCOUNT_ROLES = [
        'accounts_receivable' => 'AR_CONTROL',
        'accounts_payable' => 'AP_CONTROL',
        'bank_account' => 'BANK_MAIN',
        'sales_tax' => 'TAX_OUTPUT',
        'purchase_tax' => 'TAX_INPUT',
        'inventory' => 'INVENTORY',
        'cost_of_goods_sold' => 'COGS',
        'retained_earnings' => 'RETAINED_EARNINGS',
    ];

    /**
     * Lightweight tenant branding for documents/PDFs that don't use the
     * Template Builder (report exports). Deliberately NOT gated behind
     * settings.view: every tenant user prints reports branded with the
     * tenant's own logo/name, never the platform brand.
     */
    public function branding(): JsonResponse
    {
        $settings = DB::table('company_settings')->select('company_name', 'company_logo_url')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'company_name' => $settings->company_name ?? null,
                'company_logo_url' => $settings->company_logo_url ?? null,
            ],
        ]);
    }

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
            'company_logo_url' => 'nullable|string|max:500',
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

            // Issue #768: the legal entity is the single source of truth for
            // the fiscal year start; company_settings is the derived fallback
            // for tenants without legal entities.
            $entity = DB::table('legal_entities')->where('status', 'active')->orderBy('id')->first();
            if ($entity) {
                [$month, $day] = FiscalPeriod::parseFiscalYearStart($entity->fiscal_year_start);
            } else {
                $month = (int) ($settings->fiscal_year_start_month ?? 1);
                $day = (int) ($settings->fiscal_year_start_day ?? 1);
            }

            // Compute the fiscal year CONTAINING today from the configured
            // month/day. The stored fiscal_year_end can be stale (it produced
            // start 2026-08-01 / end 2025-08-31) and must never be returned
            // verbatim — reports default their ranges from this.
            $today = \Carbon\Carbon::today();
            $start = \Carbon\Carbon::createFromDate($today->year, $month, min($day, 28));
            if ($start->gt($today)) {
                $start->subYear();
            }
            $end = $start->copy()->addYear()->subDay();

            $fiscalYear = [
                'fiscal_year_start' => $start->format('Y-m-d'),
                'fiscal_year_end' => $end->format('Y-m-d'),
                'fiscal_year_label' => 'FY'.$end->year,
                'fiscal_year_start_month' => $month,
                'fiscal_year_start_day' => $day,
                'legal_entity_id' => $entity->id ?? null,
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

            // Issue #768: mirror the change onto the primary legal entity so
            // the entity remains the source of truth for period generation.
            $primaryEntity = DB::table('legal_entities')->orderBy('id')->first();
            if ($primaryEntity) {
                DB::table('legal_entities')->where('id', $primaryEntity->id)->update([
                    'fiscal_year_start' => $startDate->format('m-d'),
                    'updated_at' => now(),
                ]);
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

    public function accounts(Request $request): JsonResponse
    {
        try {
            // #877 (SA3): read the account-role mapping posting resolves
            // through. No company_settings row is auto-created (the previous
            // behaviour silently inserted base_currency 'SGD').
            // #885 (UX3): when an entity is selected, entity_account_roles
            // overrides the tenant-wide mapping for that entity, exactly as
            // AccountRoleResolver does when posting.
            return response()->json($this->defaultAccountsPayload($this->selectedEntityId($request)));
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
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
            'accounts_receivable' => 'nullable|integer|exists:chart_of_accounts,id',
            'accounts_payable' => 'nullable|integer|exists:chart_of_accounts,id',
            'bank_account' => 'nullable|integer|exists:chart_of_accounts,id',
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
            $entityId = $this->selectedEntityId($request);
            $roleResolver = app(AccountRoleResolver::class);
            $assignedBy = TenantUserHelper::resolveTenantUserId();

            // #877 (SA3): one source of truth — write the same mappings
            // posting reads. Fields absent from the payload are left
            // untouched; a null (cleared) field does not remove a tenant-wide
            // mapping posting depends on.
            //
            // #885 (UX3): with a selected entity, a field that has an account
            // role is written to entity_account_roles for that entity only —
            // clearing it deactivates the override and falls back to the
            // tenant-wide mapping. Fields without a role remain tenant-wide.
            foreach (self::DEFAULT_ACCOUNT_MAPPINGS as $field => $mappingKey) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }

                $value = $input[$field];
                $roleCode = self::DEFAULT_ACCOUNT_ROLES[$field] ?? null;

                if ($entityId !== null && $roleCode !== null) {
                    if ($value === null) {
                        $roleResolver->unassignByRoleCode($roleCode, $entityId);
                    } else {
                        $roleResolver->assignByRoleCode($roleCode, (int) $value, $entityId, $assignedBy);
                    }

                    continue;
                }

                if ($value === null) {
                    continue;
                }

                AccountMapping::updateOrCreate(
                    ['mapping_key' => $mappingKey, 'document_type' => null],
                    ['account_id' => (int) $value, 'is_active' => true],
                );
            }

            return response()->json($this->defaultAccountsPayload($entityId));
        } catch (\Exception $e) {
            \Log::error('Failed to update default accounts', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to update default accounts: '.$e->getMessage()], 500);
        }
    }

    /**
     * #885 (UX3): the entity the screen is editing. Only an explicit
     * selection (query/body `entity_id` or the X-Entity-Id header) scopes the
     * screen — the user's default entity from ResolveEntityContext is not
     * applied so "all entities" remains reachable.
     */
    private function selectedEntityId(Request $request): ?int
    {
        $entityId = $request->input('entity_id') ?? $request->header('X-Entity-Id');

        return $entityId ? (int) $entityId : null;
    }

    /**
     * #877 (SA3) + #885 (UX3): the screen payload — every field resolved the
     * way posting would resolve it for the selected entity, the source of
     * each value, the fields left unmapped, and the shared #775 health check.
     *
     * @return array<string, mixed>
     */
    private function defaultAccountsPayload(?int $entityId): array
    {
        $defaults = $this->defaultAccountMappings();
        $entityRoles = $entityId !== null ? $this->entityAccountRoles($entityId) : [];

        $data = [];
        $scope = [];
        $unmapped = [];

        foreach (self::DEFAULT_ACCOUNT_MAPPINGS as $field => $mappingKey) {
            $roleCode = self::DEFAULT_ACCOUNT_ROLES[$field] ?? null;

            if ($roleCode !== null && array_key_exists($roleCode, $entityRoles)) {
                $data[$field] = $entityRoles[$roleCode];
                $scope[$field] = 'entity';
            } else {
                $data[$field] = $defaults[$field] ?? null;
                $scope[$field] = 'default';
            }

            if ($data[$field] === null) {
                $unmapped[] = $field;
            }
        }

        return [
            'success' => true,
            'data' => $data,
            'entity_id' => $entityId,
            'scope' => $scope,
            'unmapped' => $unmapped,
            // #885 AC: the screen shares the #775 account-mapping health
            // check instead of inventing a second one.
            'health' => app(AccountMappingService::class)->health(),
        ];
    }

    /**
     * Active account-role assignments for one entity, keyed by role code.
     *
     * @return array<string, int>
     */
    private function entityAccountRoles(int $entityId): array
    {
        return DB::table('entity_account_roles as ear')
            ->join('account_roles as ar', 'ar.id', '=', 'ear.account_role_id')
            ->where('ear.legal_entity_id', $entityId)
            ->where('ear.is_active', true)
            ->whereIn('ar.code', array_values(self::DEFAULT_ACCOUNT_ROLES))
            ->pluck('ear.chart_of_account_id', 'ar.code')
            ->map(fn ($accountId) => (int) $accountId)
            ->all();
    }

    /**
     * #877 (SA3): the screen's fields, resolved from the account-role
     * mappings posting uses (company_settings.default_* is legacy and no
     * longer written by this controller).
     *
     * @return array<string, int|null>
     */
    private function defaultAccountMappings(): array
    {
        $accountIds = AccountMapping::query()
            ->whereNull('document_type')
            ->where('is_active', true)
            ->pluck('account_id', 'mapping_key');

        $accounts = [];

        foreach (self::DEFAULT_ACCOUNT_MAPPINGS as $field => $mappingKey) {
            $accounts[$field] = isset($accountIds[$mappingKey]) ? (int) $accountIds[$mappingKey] : null;
        }

        return $accounts;
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
            'default_bank_account_id' => null,
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

    public function documentFormats(): JsonResponse
    {
        $service = app(\App\Services\DocumentNumberingService::class);
        $formats = $service->getAllFormats();
        $formats['date_format'] = \App\Models\Setting::getValue('template_date_format', 'Y-m-d');

        return response()->json($formats);
    }

    public function updateDocumentFormats(Request $request): JsonResponse
    {
        // #542 (ST5): all six sales-document formats are editable here.
        $types = array_keys(\App\Services\DocumentNumberingService::TYPE_DEFAULTS);

        $rules = array_fill_keys($types, 'nullable|string|max:100');
        $rules['date_format'] = 'nullable|string|in:d/m/Y,m/d/Y,Y-m-d,dd/mm/yy,mm/dd/yy';

        $validated = $request->validate($rules);

        $service = app(\App\Services\DocumentNumberingService::class);

        // Validate every submitted format before saving any of them, so a bad
        // format cannot leave the settings half-updated.
        $errors = [];

        foreach ($types as $type) {
            if (! isset($validated[$type])) {
                continue;
            }

            $result = $service->validateFormat($validated[$type]);

            if (! $result['valid']) {
                $errors[$type] = $result['errors'];
            }
        }

        if ($errors !== []) {
            return response()->json([
                'message' => 'Invalid document number format.',
                'errors' => $errors,
            ], 422);
        }

        foreach ($types as $type) {
            if (isset($validated[$type])) {
                $service->setFormat($type, $validated[$type]);
            }
        }

        if (isset($validated['date_format'])) {
            \App\Models\Setting::setValue('template_date_format', $validated['date_format']);
        }

        return response()->json([
            'message' => 'Document number formats updated.',
            'formats' => $service->getAllFormats(),
            'date_format' => \App\Models\Setting::getValue('template_date_format', 'Y-m-d'),
        ]);
    }

    /**
     * Issue #540 (ST3): read-only document defaults for the sales create
     * pages. GET /settings/sales is OWNER/ADMIN-only (settings.view), so every
     * other role silently lost these defaults. This returns ONLY what a new
     * invoice / delivery order needs — no reviewer pools, user directory or
     * administrative settings.
     */
    public function getSalesDocumentDefaults(): JsonResponse
    {
        return response()->json([
            'data' => [
                'invoice' => [
                    'allow_custom_number' => (bool) \App\Models\Setting::getValue('sales.invoice.allow_custom_number', true),
                    'field_profile' => (string) \App\Models\Setting::getValue('sales.invoice.field_profile', 'medical'),
                    'default_requires_approval' => (bool) \App\Models\Setting::getValue('sales.invoice.default_requires_approval', false),
                    'default_payment_terms_days' => (int) \App\Models\Setting::getValue('sales.invoice.default_payment_terms_days', 30),
                    'default_notes' => \App\Models\Setting::getValue('sales.invoice.default_notes', ''),
                    'default_terms' => \App\Models\Setting::getValue('sales.invoice.default_terms', 'Payment due within 30 days.'),
                ],
                'delivery_order' => [
                    'default_requires_approval' => (bool) \App\Models\Setting::getValue('sales.delivery_order.default_requires_approval', false),
                ],
                'tax' => $this->salesTaxDefaults(),
            ],
        ]);
    }

    /**
     * MRD R10.3: ONE tax config surface. The legacy sales.tax.* keys are
     * derived from the canonical Setting tax block that the engine actually
     * reads — no orphan writes.
     *
     * @return array{default_tax_rate: float, prices_include_tax: bool, default_tax_treatment: string}
     */
    private function salesTaxDefaults(): array
    {
        $tax = \App\Models\Setting::getValue('tax', []);
        $defaultRate = 0.0;
        if (! empty($tax['default_tax_id'])) {
            $defaultRate = (float) (\App\Models\Tax::query()->whereKey($tax['default_tax_id'])->value('rate') ?? 0);
        }

        return [
            'default_tax_rate' => $defaultRate,
            'prices_include_tax' => ($tax['pricing_mode'] ?? 'exclusive') === 'inclusive',
            // #451: the tenant-level treatment default is a real persisted
            // choice; 'standard' is only the display default until the tenant
            // saves one.
            'default_tax_treatment' => (string) ($tax['default_tax_treatment'] ?? 'standard'),
        ];
    }

    public function getSalesSettings(): JsonResponse
    {
        return response()->json([
            'data' => [
                // Issue #333: tenant cash rounding (Odoo-style). Default OFF
                // so every existing tenant keeps byte-identical totals.
                'cash_rounding' => (static function (): array {
                    $settings = app(CashRoundingService::class)->settings();

                    return [
                        'enabled' => $settings['enabled'],
                        'method' => $settings['method'],
                        'precision' => $settings['precision'],
                        'account_id' => $settings['account_id'],
                        'methods' => CashRoundingService::METHODS,
                    ];
                })(),
                'invoice' => [
                    'allow_custom_number' => (bool) \App\Models\Setting::getValue('sales.invoice.allow_custom_number', true),
                    // D4: which extra header fields the invoice entry screen
                    // shows. 'medical' preserves the pre-setting behaviour.
                    'field_profile' => (string) \App\Models\Setting::getValue('sales.invoice.field_profile', 'medical'),
                    'default_requires_approval' => (bool) \App\Models\Setting::getValue('sales.invoice.default_requires_approval', false),
                    'approval_expiry_days' => (int) \App\Models\Setting::getValue('sales.invoice.approval_expiry_days', 7),
                    'reviewer_user_ids' => array_values(array_map('intval', (array) \App\Models\Setting::getValue('sales.invoice.reviewer_user_ids', []))),
                    'reviewer_roles' => array_values((array) \App\Models\Setting::getValue('sales.invoice.reviewer_roles', [])),
                    'available_reviewers' => \App\Models\User::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'email'])
                        ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                        ->values()
                        ->all(),
                    'auto_send_to_customer_on_post' => (bool) \App\Models\Setting::getValue('sales.invoice.auto_send_to_customer_on_post', true),
                    'allow_partial_payments' => (bool) \App\Models\Setting::getValue('sales.invoice.allow_partial_payments', true),
                    'default_payment_terms_days' => (int) \App\Models\Setting::getValue('sales.invoice.default_payment_terms_days', 30),
                    'default_notes' => \App\Models\Setting::getValue('sales.invoice.default_notes', ''),
                    'default_terms' => \App\Models\Setting::getValue('sales.invoice.default_terms', 'Payment due within 30 days.'),
                ],
                'portal' => [
                    'acceptance_enabled' => (bool) \App\Models\Setting::getValue('sales.portal.acceptance_enabled', true),
                    'require_signature' => (bool) \App\Models\Setting::getValue('sales.portal.require_signature', true),
                    'acceptance_link_expiry_days' => (int) \App\Models\Setting::getValue('sales.portal.acceptance_link_expiry_days', 30),
                    'payment_link_expiry_days' => (int) \App\Models\Setting::getValue('sales.portal.payment_link_expiry_days', 14),
                ],
                // MRD R10.3: ONE tax config surface. The legacy sales.tax.*
                // keys are now derived from the canonical Setting tax block
                // that the engine actually reads — no orphan writes.
                'tax' => $this->salesTaxDefaults(),
                'delivery' => [
                    'attach_pdf' => (bool) \App\Models\Setting::getValue('sales.delivery.attach_pdf', true),
                    'cc_internal_email' => \App\Models\Setting::getValue('sales.delivery.cc_internal_email', ''),
                    'document_type_on_send' => \App\Models\Setting::getValue('sales.delivery.document_type_on_send', 'final_invoice'),
                ],
                // Issue #645: per-request-type reviewer lists. Each type falls
                // back to the invoice pool when nothing is configured here, so
                // existing tenants keep working unchanged.
                'quotation' => [
                    'reviewer_user_ids' => array_values(array_map('intval', (array) \App\Models\Setting::getValue('sales.quotation.reviewer_user_ids', []))),
                    'reviewer_roles' => array_values((array) \App\Models\Setting::getValue('sales.quotation.reviewer_roles', [])),
                ],
                'sales_order' => [
                    'reviewer_user_ids' => array_values(array_map('intval', (array) \App\Models\Setting::getValue('sales.sales_order.reviewer_user_ids', []))),
                    'reviewer_roles' => array_values((array) \App\Models\Setting::getValue('sales.sales_order.reviewer_roles', [])),
                ],
                'invoice_threshold' => [
                    'reviewer_user_ids' => array_values(array_map('intval', (array) \App\Models\Setting::getValue('sales.invoice_threshold.reviewer_user_ids', []))),
                    'reviewer_roles' => array_values((array) \App\Models\Setting::getValue('sales.invoice_threshold.reviewer_roles', [])),
                ],
                'delivery_order' => [
                    'default_requires_approval' => (bool) \App\Models\Setting::getValue('sales.delivery_order.default_requires_approval', false),
                    'reviewer_user_ids' => array_values(array_map('intval', (array) \App\Models\Setting::getValue('sales.delivery_order.reviewer_user_ids', []))),
                    'reviewer_roles' => array_values((array) \App\Models\Setting::getValue('sales.delivery_order.reviewer_roles', [])),
                ],
                // Issue #553: creation defaults for new sales documents.
                // salesperson_mode = current_user stamps the creating user's
                // name; team_by_user maps a user id to their default team.
                'defaults' => [
                    'salesperson_mode' => (string) \App\Models\Setting::getValue('sales.defaults.salesperson_mode', 'none'),
                    'team_by_user' => (static function (): array {
                        $map = \App\Models\Setting::getValue('sales.defaults.team_by_user', []);
                        if (! is_array($map)) {
                            return [];
                        }

                        $clean = [];
                        foreach ($map as $userId => $team) {
                            if (is_string($team) && trim($team) !== '') {
                                $clean[(string) $userId] = trim($team);
                            }
                        }

                        return $clean;
                    })(),
                ],
                // Issue #553: line pricelist presentation on rendered documents.
                'pricelist' => [
                    'display' => (string) \App\Models\Setting::getValue('sales.pricelist.display', 'show_discount'),
                ],
            ],
        ]);
    }

    public function updateSalesSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Issue #333: tenant cash rounding. The rounding account is a
            // tenant-chosen chart_of_accounts row (never auto-seeded).
            'cash_rounding.enabled' => 'nullable|boolean',
            'cash_rounding.method' => ['nullable', \Illuminate\Validation\Rule::in(CashRoundingService::METHODS)],
            'cash_rounding.precision' => 'nullable|numeric|gt:0|max:100',
            'cash_rounding.account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'invoice.allow_custom_number' => 'nullable|boolean',
            'invoice.field_profile' => 'nullable|string|in:general,medical,trading,services',
            'invoice.default_requires_approval' => 'nullable|boolean',
            'invoice.approval_expiry_days' => 'nullable|integer|min:1|max:90',
            'invoice.reviewer_user_ids' => 'nullable|array',
            'invoice.reviewer_user_ids.*' => 'integer|exists:users,id',
            'invoice.reviewer_roles' => 'nullable|array',
            'invoice.reviewer_roles.*' => 'string|in:owner,admin,manager,sales,accountant,cashier,viewer',
            'invoice.auto_send_to_customer_on_post' => 'nullable|boolean',
            'invoice.allow_partial_payments' => 'nullable|boolean',
            'invoice.default_payment_terms_days' => 'nullable|integer|min:0|max:365',
            'invoice.default_notes' => 'nullable|string|max:1000',
            'invoice.default_terms' => 'nullable|string|max:1000',
            'portal.acceptance_enabled' => 'nullable|boolean',
            'portal.require_signature' => 'nullable|boolean',
            'portal.acceptance_link_expiry_days' => 'nullable|integer|min:1|max:365',
            'portal.payment_link_expiry_days' => 'nullable|integer|min:1|max:365',
            'tax.default_tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax.prices_include_tax' => 'nullable|boolean',
            'tax.default_tax_treatment' => 'nullable|string|in:standard,zero_rated,exempt,out_of_scope',
            'delivery.attach_pdf' => 'nullable|boolean',
            'delivery.cc_internal_email' => 'nullable|email|max:255',
            'delivery.document_type_on_send' => 'nullable|string|in:final_invoice,acceptance_copy',
            'delivery_order.default_requires_approval' => 'nullable|boolean',
            // Issue #645: per-request-type reviewer lists (quotation, sales
            // order, invoice threshold, delivery order).
            'quotation.reviewer_user_ids' => 'nullable|array',
            'quotation.reviewer_user_ids.*' => 'integer|exists:users,id',
            'quotation.reviewer_roles' => 'nullable|array',
            'quotation.reviewer_roles.*' => 'string|in:owner,admin,manager,sales,accountant,cashier,viewer',
            'sales_order.reviewer_user_ids' => 'nullable|array',
            'sales_order.reviewer_user_ids.*' => 'integer|exists:users,id',
            'sales_order.reviewer_roles' => 'nullable|array',
            'sales_order.reviewer_roles.*' => 'string|in:owner,admin,manager,sales,accountant,cashier,viewer',
            'invoice_threshold.reviewer_user_ids' => 'nullable|array',
            'invoice_threshold.reviewer_user_ids.*' => 'integer|exists:users,id',
            'invoice_threshold.reviewer_roles' => 'nullable|array',
            'invoice_threshold.reviewer_roles.*' => 'string|in:owner,admin,manager,sales,accountant,cashier,viewer',
            'delivery_order.reviewer_user_ids' => 'nullable|array',
            'delivery_order.reviewer_user_ids.*' => 'integer|exists:users,id',
            'delivery_order.reviewer_roles' => 'nullable|array',
            'delivery_order.reviewer_roles.*' => 'string|in:owner,admin,manager,sales,accountant,cashier,viewer',
            // Issue #553: new-document creation defaults + pricelist display.
            'defaults.salesperson_mode' => 'nullable|in:none,current_user',
            'defaults.team_by_user' => 'nullable|array',
            'defaults.team_by_user.*' => 'nullable|string|max:255',
            'pricelist.display' => 'nullable|in:show_discount,net_price',
        ]);

        $map = [
            'invoice.allow_custom_number' => 'sales.invoice.allow_custom_number',
            'invoice.field_profile' => 'sales.invoice.field_profile',
            'invoice.default_requires_approval' => 'sales.invoice.default_requires_approval',
            'invoice.approval_expiry_days' => 'sales.invoice.approval_expiry_days',
            'invoice.reviewer_user_ids' => 'sales.invoice.reviewer_user_ids',
            'invoice.reviewer_roles' => 'sales.invoice.reviewer_roles',
            'invoice.auto_send_to_customer_on_post' => 'sales.invoice.auto_send_to_customer_on_post',
            'invoice.allow_partial_payments' => 'sales.invoice.allow_partial_payments',
            'invoice.default_payment_terms_days' => 'sales.invoice.default_payment_terms_days',
            'invoice.default_notes' => 'sales.invoice.default_notes',
            'invoice.default_terms' => 'sales.invoice.default_terms',
            'portal.acceptance_enabled' => 'sales.portal.acceptance_enabled',
            'portal.require_signature' => 'sales.portal.require_signature',
            'portal.acceptance_link_expiry_days' => 'sales.portal.acceptance_link_expiry_days',
            'portal.payment_link_expiry_days' => 'sales.portal.payment_link_expiry_days',
            'delivery.attach_pdf' => 'sales.delivery.attach_pdf',
            'delivery.cc_internal_email' => 'sales.delivery.cc_internal_email',
            'delivery.document_type_on_send' => 'sales.delivery.document_type_on_send',
            'delivery_order.default_requires_approval' => 'sales.delivery_order.default_requires_approval',
            'quotation.reviewer_user_ids' => 'sales.quotation.reviewer_user_ids',
            'quotation.reviewer_roles' => 'sales.quotation.reviewer_roles',
            'sales_order.reviewer_user_ids' => 'sales.sales_order.reviewer_user_ids',
            'sales_order.reviewer_roles' => 'sales.sales_order.reviewer_roles',
            'invoice_threshold.reviewer_user_ids' => 'sales.invoice_threshold.reviewer_user_ids',
            'invoice_threshold.reviewer_roles' => 'sales.invoice_threshold.reviewer_roles',
            'delivery_order.reviewer_user_ids' => 'sales.delivery_order.reviewer_user_ids',
            'delivery_order.reviewer_roles' => 'sales.delivery_order.reviewer_roles',
            // Issue #553: new-document defaults + pricelist display.
            'defaults.salesperson_mode' => 'sales.defaults.salesperson_mode',
            'defaults.team_by_user' => 'sales.defaults.team_by_user',
            'pricelist.display' => 'sales.pricelist.display',
        ];

        // MRD R10.3: tax writes go to the ONE canonical Setting tax block
        // the engine reads. default_tax_rate re-points default_tax_id to a
        // matching active rule (never invents one); #451 persists the
        // tenant-level treatment default the resolver falls back to.
        if (array_key_exists('tax', $validated) && is_array($validated['tax'])) {
            $taxSetting = \App\Models\Setting::getValue('tax', []);

            if (array_key_exists('prices_include_tax', $validated['tax'])) {
                $taxSetting['pricing_mode'] = $validated['tax']['prices_include_tax'] ? 'inclusive' : 'exclusive';
            }

            if (array_key_exists('default_tax_treatment', $validated['tax'])) {
                $taxSetting['default_tax_treatment'] = $validated['tax']['default_tax_treatment'];
            }

            if (array_key_exists('default_tax_rate', $validated['tax'])) {
                $rate = (float) $validated['tax']['default_tax_rate'];

                if ($rate <= 0) {
                    $taxSetting['default_tax_id'] = null;
                } else {
                    $match = \App\Models\Tax::query()
                        ->where('is_active', true)
                        ->whereRaw('ABS(rate - ?) < 0.005', [$rate])
                        ->orderByDesc('is_default')
                        ->first();

                    if ($match) {
                        $taxSetting['default_tax_id'] = $match->id;
                    }
                }
            }

            \App\Models\Setting::setValue('tax', array_merge([
                'enabled' => true,
                'pricing_mode' => 'exclusive',
                'default_tax_id' => null,
                'jurisdiction' => null,
            ], $taxSetting));
        }

        // Issue #553: drop blank team assignments before persisting so clearing
        // an input actually removes the mapping, and trim surrounding spaces.
        if (isset($validated['defaults']['team_by_user']) && is_array($validated['defaults']['team_by_user'])) {
            $cleanTeams = [];
            foreach ($validated['defaults']['team_by_user'] as $userId => $team) {
                if (is_string($team) && trim($team) !== '') {
                    $cleanTeams[(string) $userId] = trim($team);
                }
            }
            $validated['defaults']['team_by_user'] = $cleanTeams;
        }

        // Issue #333: cash rounding is ONE JSON block under the canonical
        // `sales.cash_rounding` key (the key CashRoundingService reads), so
        // partial updates merge instead of overwriting sibling fields.
        if (array_key_exists('cash_rounding', $validated) && is_array($validated['cash_rounding'])) {
            $rounding = array_merge(
                CashRoundingService::DEFAULTS,
                \App\Models\Setting::getValue(CashRoundingService::SETTING_KEY, []),
            );

            $input = $validated['cash_rounding'];

            if (array_key_exists('enabled', $input)) {
                $rounding['enabled'] = (bool) $input['enabled'];
            }
            if (array_key_exists('method', $input)) {
                $rounding['method'] = $input['method'];
            }
            if (array_key_exists('precision', $input)) {
                $rounding['precision'] = (float) $input['precision'];
            }
            if (array_key_exists('account_id', $input)) {
                $rounding['account_id'] = $input['account_id'] !== null ? (int) $input['account_id'] : null;
            }

            \App\Models\Setting::setValue(CashRoundingService::SETTING_KEY, $rounding);
        }

        // $validated arrives nested (['invoice' => ['key' => value]]), while
        // $map is keyed in dot notation — walk the groups so every leaf is
        // matched. (The previous flat iteration silently persisted nothing.)
        foreach ($validated as $group => $values) {
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                $dotKey = "{$group}.{$key}";

                if (isset($map[$dotKey])) {
                    \App\Models\Setting::setValue($map[$dotKey], $value);
                }
            }
        }

        return $this->getSalesSettings();
    }
}
