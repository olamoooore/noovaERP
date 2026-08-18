<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\CustomerLoyaltyProgram;
use App\Services\CustomerContactSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;

class CustomerController extends Controller
{
    private const NUMERIC_PHONE_RULE = 'nullable|regex:/^[0-9]+$/|max:50';

    public function index(Request $request): JsonResponse
    {
        $query = Customer::withCount(['invoices'])
            ->with([
                'categories',
                'priceList',
                'salesperson',
                'loyaltyBalances.program',
                'customerLoyalty.tier',
                'customerLoyalty.program',
            ]);

        // Filters
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('company', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->is_active !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->category_id) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('customer_categories.id', $request->category_id);
            });
        }

        if ($request->salesperson_id) {
            $query->where('salesperson_id', $request->salesperson_id);
        }

        if ($request->boolean('has_balance')) {
            $query->whereHas('invoices', function ($q) {
                $q->where('status', '!=', 'paid')
                    ->where('status', '!=', 'draft');
            });
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'name';
        $sortDir = $request->sort_dir ?? 'asc';
        $query->orderBy($sortBy, $sortDir);

        $customers = $query->paginate($request->per_page ?? 20);

        // Transform data to include loyalty info for POS
        $customers->getCollection()->transform(function ($customer) {
            $loyalty = $customer->customerLoyalty;
            $customer->current_points = $loyalty?->current_points ?? 0;
            $customer->loyalty_tier_name = $loyalty?->tier?->name;
            $customer->is_enrolled = $loyalty !== null;

            return $customer;
        });

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Basic
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'code')->whereNull('deleted_at'),
            ],
            'type' => 'in:individual,company',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => self::NUMERIC_PHONE_RULE,
            'mobile' => self::NUMERIC_PHONE_RULE,
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
            'company_registration_number' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:255',
            'language' => 'nullable|string|max:10',

            // Addresses (legacy)
            'billing_address' => 'nullable|string',
            'shipping_address' => 'nullable|string',

            // Financial
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:0',
            'price_list_id' => 'nullable|exists:customer_price_lists,id',

            // Personal
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',

            // Relationships
            'parent_id' => 'nullable|exists:customers,id',
            'salesperson_id' => 'nullable|exists:users,id',

            // Preferences
            'preferred_contact' => 'nullable|in:email,phone,sms,whatsapp',
            'marketing_opt_in' => 'boolean',

            // Status
            'is_active' => 'boolean',
            'notes' => 'nullable|string',

            // Relations
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:customer_categories,id',

            // Addresses array
            'addresses' => 'nullable|array',
            'addresses.*.type' => 'required|in:billing,shipping,other',
            'addresses.*.label' => 'nullable|string|max:100',
            'addresses.*.street' => 'nullable|string|max:255',
            'addresses.*.street2' => 'nullable|string|max:255',
            'addresses.*.city' => 'nullable|string|max:100',
            'addresses.*.state' => 'nullable|string|max:100',
            'addresses.*.zip' => 'nullable|string|max:20',
            'addresses.*.country' => 'nullable|string|max:100',
            'addresses.*.is_default' => 'boolean',

            // Contacts array
            'contacts' => 'nullable|array',
            'contacts.*.name' => 'required|string|max:255',
            'contacts.*.job_title' => 'nullable|string|max:100',
            'contacts.*.contact_category' => 'nullable|string|max:100',
            'contacts.*.contact_type' => 'nullable|string|max:100',
            'contacts.*.email' => 'nullable|email',
            'contacts.*.phone' => self::NUMERIC_PHONE_RULE,
            'contacts.*.mobile' => self::NUMERIC_PHONE_RULE,
            'contacts.*.street' => 'nullable|string|max:255',
            'contacts.*.street2' => 'nullable|string|max:255',
            'contacts.*.city' => 'nullable|string|max:100',
            'contacts.*.state' => 'nullable|string|max:100',
            'contacts.*.zip' => 'nullable|string|max:20',
            'contacts.*.country' => 'nullable|string|max:100',
            'contacts.*.is_primary' => 'boolean',
            'contacts.*.notes' => 'nullable|string',
        ]);

        $validated = $this->normalizeCustomerIdentityFields($validated);
        $this->ensureUniqueCustomerIdentity($validated);

        // Extract relations
        $categoryIds = $validated['category_ids'] ?? [];
        $addresses = $validated['addresses'] ?? [];
        $contacts = $validated['contacts'] ?? [];
        unset($validated['category_ids'], $validated['addresses'], $validated['contacts']);

        // ADR-003A: Wrap customer + contact creation in a single transaction.
        // If any step fails, the entire operation rolls back — no partial state.
        $customer = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $categoryIds, $addresses, $contacts) {
            $customer = Customer::create($validated);

            // Attach categories
            if (! empty($categoryIds)) {
                $customer->categories()->attach($categoryIds);
            }

            // Create addresses
            foreach ($addresses as $address) {
                $customer->addresses()->create($address);
            }

            // ADR-003A CT-VEN-003/004: Every customer creation must route through
            // Contact Master to ensure canonical identity linkage.
            $sync = app(CustomerContactSyncService::class);
            $sync->ensureOrganisationContact($customer);

            // Create contacts via Contact Master sync (ensures core_contact_uuid is set).
            foreach ($contacts as $contact) {
                $legacy = $sync->transformLegacyPayload(
                    $contact['contact_category'] ?? null,
                    $contact['contact_type'] ?? null
                );
                $canonical = [
                    'functional_category_code' => $contact['functional_category_code'] ?? $legacy['functional_category_code'],
                    'classification_code' => $contact['classification_code'] ?? $legacy['classification_code'],
                    'persistent_role_codes' => $contact['persistent_role_codes'] ?? $legacy['persistent_role_codes'],
                    'department_name' => $contact['department_name'] ?? null,
                ];
                $contactModel = $customer->customerContacts()->create(collect($contact)->only([
                    'name', 'job_title', 'email', 'phone', 'mobile',
                    'street', 'street2', 'city', 'state', 'zip', 'country', 'notes',
                    'contact_category', 'contact_type', 'department_name',
                ])->all());
                $sync->syncPersonForCustomerContact($customer, $contactModel, $canonical);

                if ((bool) ($contact['is_primary'] ?? false) || $legacy['is_primary']) {
                    $sync->setPrimaryContact($customer, $contactModel->id);
                }
            }

            return $customer;
        });

        // Initialize loyalty if program exists
        $program = CustomerLoyaltyProgram::where('is_active', true)->first();
        if ($program) {
            $customer->loyaltyBalances()->create([
                'program_id' => $program->id,
                'points_balance' => 0,
            ]);
        }

        return response()->json(
            $customer->load(['categories', 'addresses', 'customerContacts', 'loyaltyBalances']),
            201
        );
    }

    public function show($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        return response()->json(
            $customer->load([
                'categories',
                'addresses',
                'customerContacts',
                'priceList',
                'salesperson',
                'loyaltyBalances.program',
                'parent',
                'contacts',
                'invoices' => fn ($q) => $q->latest()->limit(10),
                'posOrders' => fn ($q) => $q->with('items.product:id,name')->latest()->limit(10),
                'webOrders' => fn ($q) => $q->with('items.product')->latest()->limit(10),
            ])
        );
    }

    public function update(Request $request, $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $validated = $request->validate([
            // Basic
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'code')
                    ->ignore($customer->getKey())
                    ->whereNull('deleted_at'),
            ],
            'type' => 'in:individual,company',
            'name' => 'string|max:255',
            'email' => 'nullable|email',
            'phone' => self::NUMERIC_PHONE_RULE,
            'mobile' => self::NUMERIC_PHONE_RULE,
            'street' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
            'company_registration_number' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:255',
            'language' => 'nullable|string|max:10',

            // Addresses (legacy)
            'billing_address' => 'nullable|string',
            'shipping_address' => 'nullable|string',

            // Financial
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:0',
            'price_list_id' => 'nullable|exists:customer_price_lists,id',

            // Personal
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',

            // Relationships
            'parent_id' => 'nullable|exists:customers,id',
            'salesperson_id' => 'nullable|exists:users,id',

            // Preferences
            'preferred_contact' => 'nullable|in:email,phone,sms,whatsapp',
            'marketing_opt_in' => 'boolean',

            // Status
            'is_active' => 'boolean',
            'notes' => 'nullable|string',

            // Relations
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:customer_categories,id',
        ]);

        $validated = $this->normalizeCustomerIdentityFields($validated);
        $this->ensureUniqueCustomerIdentity($validated, $customer);

        // Extract category IDs
        $categoryIds = $validated['category_ids'] ?? null;
        unset($validated['category_ids']);

        $customer->update($validated);

        // Sync categories if provided
        if ($categoryIds !== null) {
            $customer->categories()->sync($categoryIds);
        }

        return response()->json(
            $customer->load(['categories', 'addresses', 'customerContacts', 'priceList', 'salesperson'])
        );
    }

    public function destroy($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    // ==========================================
    // ADDRESSES
    // ==========================================

    public function addAddress(Request $request, $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|in:billing,shipping,other',
            'label' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_default' => 'boolean',
        ]);

        $address = $customer->addresses()->create($validated);

        return response()->json($address, 201);
    }

    public function updateAddress(Request $request, $customerId, $addressId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $address = $customer->addresses()->findOrFail($addressId);

        $validated = $request->validate([
            'type' => 'in:billing,shipping,other',
            'label' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_default' => 'boolean',
        ]);

        $address->update($validated);

        return response()->json($address);
    }

    public function deleteAddress($customerId, $addressId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $address = $customer->addresses()->findOrFail($addressId);

        $address->delete();

        return response()->json(['message' => 'Address deleted']);
    }

    // ==========================================
    // CONTACTS
    // ADR-003A: canonical persistence lives in the Contact Master
    // (core_contacts + org-person relationships). customer_contacts is the
    // CRM customer-to-person association carrying the is_primary attribute
    // and non-authoritative legacy compatibility fields.
    // ==========================================

    public function addContact(Request $request, $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'job_title' => 'nullable|string|max:100',
            'contact_category' => 'nullable|string|max:100',
            'contact_type' => 'nullable|string|max:100',
            'department_name' => 'nullable|string|max:150',
            // Canonical fields (ADR-003A §7.1) take precedence over legacy.
            'functional_category_code' => 'nullable|string|max:50',
            'classification_code' => 'nullable|string|max:50',
            'persistent_role_codes' => 'sometimes|array',
            'persistent_role_codes.*' => 'string|max:50',
            'email' => 'nullable|email',
            'phone' => self::NUMERIC_PHONE_RULE,
            'mobile' => self::NUMERIC_PHONE_RULE,
            'street' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_primary' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            [$contact] = $this->syncContact($customer, $validated, null);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['contact_type' => [$e->getMessage()]]);
        }

        return response()->json($contact, 201);
    }

    public function updateContact(Request $request, $customerId, $contactId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $contact = $customer->customerContacts()->findOrFail($contactId);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'job_title' => 'nullable|string|max:100',
            'contact_category' => 'nullable|string|max:100',
            'contact_type' => 'nullable|string|max:100',
            'department_name' => 'nullable|string|max:150',
            'functional_category_code' => 'nullable|string|max:50',
            'classification_code' => 'nullable|string|max:50',
            'persistent_role_codes' => 'sometimes|array',
            'persistent_role_codes.*' => 'string|max:50',
            'email' => 'nullable|email',
            'phone' => self::NUMERIC_PHONE_RULE,
            'mobile' => self::NUMERIC_PHONE_RULE,
            'street' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_primary' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            [$contact] = $this->syncContact($customer, $validated, $contact);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['contact_type' => [$e->getMessage()]]);
        }

        return response()->json($contact);
    }

    public function deleteContact($customerId, $contactId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $contact = $customer->customerContacts()->findOrFail($contactId);

        $contact->delete();

        return response()->json(['message' => 'Contact deleted']);
    }

    /**
     * ADR-003A CT-VEN-028: atomic SetPrimaryContact(customer, person).
     * Clears the prior active primary and sets the new one in one protected
     * operation; the prior contact remains associated and otherwise unchanged.
     */
    public function setPrimaryContact(Request $request, $customerId, $contactId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);

        try {
            $contact = app(CustomerContactSyncService::class)
                ->setPrimaryContact($customer, (int) $contactId, $request->user()?->id);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['contact' => [$e->getMessage()]]);
        }

        return response()->json($contact);
    }

    /**
     * Create/update a customer contact and synchronize canonical Contact
     * Master structures. Returns [CustomerContact, array $canonical].
     */
    private function syncContact(Customer $customer, array $data, $existing): array
    {
        $sync = app(CustomerContactSyncService::class);

        // Legacy contact_category/contact_type are non-authoritative inputs;
        // canonical fields win when both are supplied (ADR-003A §7.2).
        $legacy = $sync->transformLegacyPayload(
            $data['contact_category'] ?? null,
            $data['contact_type'] ?? null
        );

        if (! empty($legacy['unknown_values'])) {
            // CT-VEN-016: unknown controlled values are never auto-created.
            throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
        }

        $canonical = [
            'functional_category_code' => $data['functional_category_code'] ?? $legacy['functional_category_code'],
            'classification_code' => $data['classification_code'] ?? $legacy['classification_code'],
            'persistent_role_codes' => $data['persistent_role_codes'] ?? $legacy['persistent_role_codes'],
            'department_name' => $data['department_name'] ?? null,
        ];

        if ($canonical['functional_category_code'] !== null) {
            $canonical['functional_category_code'] = ContactTaxonomyRegistry::resolveCode(
                ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY,
                $canonical['functional_category_code']
            ) ?? throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
        }
        if ($canonical['classification_code'] !== null) {
            $canonical['classification_code'] = ContactTaxonomyRegistry::resolveCode(
                ContactTaxonomyRegistry::TYPE_CLASSIFICATION,
                $canonical['classification_code']
            ) ?? throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
        }
        $canonical['persistent_role_codes'] = array_values(array_filter(array_map(
            fn ($code) => ContactTaxonomyRegistry::resolveCode(ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE, $code)
                ?? throw new \DomainException('UNKNOWN_TAXONOMY_VALUE'),
            (array) $canonical['persistent_role_codes']
        )));

        $isPrimary = (bool) ($data['is_primary'] ?? false) || $legacy['is_primary'];

        // Deterministic legacy write projection for the compatibility columns.
        $rowCategory = $canonical['functional_category_code'] ?? $data['contact_category'] ?? null;
        $rowType = $data['contact_type'] ?? null;
        if ($legacy['classification_code'] || $legacy['persistent_role_codes'] || $legacy['is_primary']) {
            $rowType = LegacyContactTypeMapping::projectLegacyContactType(
                array_filter([$canonical['classification_code']]),
                $canonical['persistent_role_codes']
            );
            if ($legacy['is_primary'] && ! $canonical['classification_code'] && ! $canonical['persistent_role_codes']) {
                $rowType = $data['contact_type'] ?? null; // Primary only: keep as-is; flag carries it.
            }
        }

        $attributes = collect($data)->only([
            'name', 'job_title', 'email', 'phone', 'mobile',
            'street', 'street2', 'city', 'state', 'zip', 'country', 'notes',
        ])->all();
        $attributes['contact_category'] = $rowCategory;
        $attributes['contact_type'] = $rowType;
        $attributes['department_name'] = $canonical['department_name'];
        // is_primary is never written directly; it is applied atomically below.
        unset($attributes['is_primary']);

        $contact = $existing
            ? tap($existing)->update($attributes)
            : $customer->customerContacts()->create($attributes);

        $personUuid = $sync->syncPersonForCustomerContact($customer, $contact, $canonical);

        if ($isPrimary) {
            $contact = $sync->setPrimaryContact($customer, $contact->id);
        }

        return [$contact->fresh(), array_merge($canonical, ['core_contact_uuid' => $personUuid])];
    }

    // ==========================================
    // LOYALTY
    // ==========================================

    public function loyaltyHistory($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        // Try to fetch from Loyalty Module first
        $moduleLoyalty = $customer->customerLoyalty;

        if ($moduleLoyalty) {
            $transactions = $moduleLoyalty->transactions()
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            // Map fields for frontend compatibility
            $transactions->getCollection()->transform(function ($tx) {
                $tx->notes = $tx->description; // detailed description

                // If type is adjust, maybe map it?
                // Module types: earn, redeem, expire, adjust (seems compatible)
                return $tx;
            });
        } else {
            // Legacy fallback
            $transactions = $customer->loyaltyTransactions()
                ->with('program')
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }

        return response()->json([
            'balance' => $customer->loyalty_points,
            'transactions' => $transactions,
        ]);
    }

    public function adjustLoyalty(Request $request, $customer): JsonResponse
    {
        $customer = Customer::findOrFail($customer);
        $validated = $request->validate([
            'points' => 'required|integer|not_in:0',
            'notes' => 'nullable|string',
        ]);

        // Use canonical PointsEarningService — no legacy Customer::addLoyaltyPoints() bypass
        $earningService = app(\Modules\Loyalty\Services\PointsEarningService::class);
        $reason = $validated['notes'] ?? 'Manual adjustment';

        $earningService->adjustPoints(
            $customer->id,
            $validated['points'],
            $reason
        );

        return response()->json([
            'message' => 'Loyalty points adjusted',
            'balance' => $customer->fresh()->loyalty_points,
        ]);
    }

    // ==========================================
    // CATEGORIES
    // ==========================================

    public function categories(): JsonResponse
    {
        $categories = CustomerCategory::withCount('customers')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    private function normalizeCustomerIdentityFields(array $validated): array
    {
        if (array_key_exists('email', $validated)) {
            $validated['email'] = $this->normalizeEmail($validated['email']);
        }

        foreach (['phone', 'mobile'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = $this->normalizePhone($validated[$field]);
            }
        }

        return $validated;
    }

    private function ensureUniqueCustomerIdentity(array $validated, ?Customer $ignoreCustomer = null): void
    {
        $messages = [];

        $email = $validated['email'] ?? null;
        $emailChanged = ! $ignoreCustomer
            || (array_key_exists('email', $validated)
                && $email !== $this->normalizeEmail($ignoreCustomer->email));

        if ($email && $emailChanged) {
            $duplicateEmail = Customer::query()
                ->when($ignoreCustomer, fn ($query) => $query->whereKeyNot($ignoreCustomer->id))
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($duplicateEmail) {
                $messages['email'][] = 'A customer with this email address already exists.';
            }
        }

        $incomingPhones = collect(['phone', 'mobile'])
            ->filter(function (string $field) use ($validated, $ignoreCustomer) {
                if (empty($validated[$field])) {
                    return false;
                }

                if (! $ignoreCustomer) {
                    return true;
                }

                return array_key_exists($field, $validated)
                    && $validated[$field] !== $this->normalizePhone($ignoreCustomer->{$field});
            })
            ->mapWithKeys(fn (string $field) => [$field => $validated[$field]]);

        if ($incomingPhones->isNotEmpty()) {
            $candidates = Customer::query()
                ->select(['id', 'phone', 'mobile'])
                ->when($ignoreCustomer, fn ($query) => $query->whereKeyNot($ignoreCustomer->id))
                ->where(function ($query) {
                    $query->whereNotNull('phone')->orWhereNotNull('mobile');
                })
                ->get();

            foreach ($incomingPhones as $field => $phone) {
                $duplicatePhone = $candidates->first(function (Customer $candidate) use ($phone) {
                    return $phone === $this->normalizePhone($candidate->phone)
                        || $phone === $this->normalizePhone($candidate->mobile);
                });

                if ($duplicatePhone) {
                    $messages[$field][] = 'A customer with this phone number already exists.';
                }
            }
        }

        if (! empty($messages)) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = is_string($value) ? mb_strtolower(trim($value)) : null;

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePhone(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $value);

        return $normalized !== '' ? $normalized : null;
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
        ]);

        $category = CustomerCategory::create($validated);

        return response()->json($category, 201);
    }

    // ==========================================
    // SEARCH & LOOKUP
    // ==========================================

    public function search(Request $request): JsonResponse
    {
        $search = $request->q;

        $customers = Customer::where(function ($q) use ($search) {
            $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('mobile', 'ilike', "%{$search}%")
                ->orWhere('company', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%");
        })
            ->where('is_active', true)
            ->limit(20)
            ->get(['id', 'code', 'name', 'email', 'phone', 'company', 'type']);

        return response()->json(['customers' => $customers]);
    }

    // ==========================================
    // STATISTICS & ANALYTICS
    // ==========================================

    /**
     * Get overall customer statistics
     */
    public function statistics(): JsonResponse
    {
        $totalCustomers = Customer::count();
        $activeCustomers = Customer::where('is_active', true)->count();
        $companies = Customer::where('type', 'company')->count();
        $individuals = Customer::where('type', 'individual')->count();

        $totalOutstanding = Customer::sum('total_outstanding');
        $totalPurchases = Customer::sum('total_purchases');

        $newThisMonth = Customer::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $topCustomers = Customer::where('is_active', true)
            ->orderBy('total_purchases', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'company', 'total_purchases', 'total_orders']);

        return response()->json([
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'companies' => $companies,
            'individuals' => $individuals,
            'total_outstanding' => $totalOutstanding,
            'total_purchases' => $totalPurchases,
            'new_this_month' => $newThisMonth,
            'top_customers' => $topCustomers,
        ]);
    }

    /**
     * Get individual customer statistics
     */
    public function customerStatistics($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        // Calculate additional stats
        $posOrderStats = $customer->posOrders()
            ->where('status', 'paid')
            ->where('is_return', false)
            ->selectRaw('
                COUNT(*) as total_orders,
                SUM(total) as total_revenue,
                AVG(total) as avg_order_value,
                MAX(total) as highest_order,
                MIN(total) as lowest_order,
                MAX(created_at) as last_order_date
            ')
            ->first();

        $invoiceStats = $customer->invoices()
            ->whereIn('status', ['sent', 'paid'])
            ->selectRaw('
                COUNT(*) as total_invoices,
                SUM(total) as total_invoiced,
                SUM(balance) as outstanding_balance,
                SUM(CASE WHEN status = \'paid\' THEN total ELSE 0 END) as paid_amount
            ')
            ->first();

        // Monthly trend (last 12 months)
        $monthlyTrend = $customer->posOrders()
            ->where('status', 'paid')
            ->where('is_return', false)
            ->where('created_at', '>=', now()->subMonths(12))
            ->selectRaw('DATE_TRUNC(\'month\', created_at) as month, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Favorite products
        $favoriteProducts = $customer->posOrders()
            ->where('status', 'paid')
            ->join('pos_order_items', 'pos_orders.id', '=', 'pos_order_items.pos_order_id')
            ->join('products', 'pos_order_items.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.name, SUM(pos_order_items.quantity) as total_qty, SUM(pos_order_items.line_total) as total_spent')
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_qty', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'pos_orders' => $posOrderStats,
            'invoices' => $invoiceStats,
            'monthly_trend' => $monthlyTrend,
            'favorite_products' => $favoriteProducts,
            'loyalty_points' => $customer->loyalty_points,
            'customer_since' => $customer->customer_since,
            'last_interaction' => $customer->last_interaction_at,
        ]);
    }

    /**
     * Get customer activity timeline
     */
    public function activity($id, Request $request): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $limit = $request->limit ?? 20;
        $activities = collect();

        // POS Orders
        $posOrders = $customer->posOrders()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($order) {
                return [
                    'type' => 'pos_order',
                    'id' => $order->id,
                    'title' => "POS Order #{$order->order_number}",
                    'description' => $order->status === 'paid' ? 'Completed purchase' : ucfirst($order->status),
                    'amount' => $order->total,
                    'status' => $order->status,
                    'date' => $order->paid_at ?? $order->created_at,
                    'icon' => 'cart',
                ];
            });

        // Invoices
        $invoices = $customer->invoices()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($invoice) {
                return [
                    'type' => 'invoice',
                    'id' => $invoice->id,
                    'title' => "Invoice #{$invoice->invoice_number}",
                    'description' => ucfirst($invoice->status),
                    'amount' => $invoice->total,
                    'status' => $invoice->status,
                    'date' => $invoice->invoice_date,
                    'icon' => 'document',
                ];
            });

        // Payments
        $payments = $customer->payments()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($payment) {
                return [
                    'type' => 'payment',
                    'id' => $payment->id,
                    'title' => "Payment #{$payment->payment_number}",
                    'description' => ucfirst($payment->method).' payment',
                    'amount' => $payment->amount,
                    'status' => 'completed',
                    'date' => $payment->payment_date,
                    'icon' => 'money',
                ];
            });

        // Loyalty transactions
        $loyaltyTx = $customer->loyaltyTransactions()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($tx) {
                return [
                    'type' => 'loyalty',
                    'id' => $tx->id,
                    'title' => $tx->type === 'earn' ? 'Points Earned' : ($tx->type === 'redeem' ? 'Points Redeemed' : ucfirst($tx->type)),
                    'description' => $tx->notes ?? ($tx->points > 0 ? "+{$tx->points} points" : "{$tx->points} points"),
                    'amount' => null,
                    'points' => $tx->points,
                    'status' => 'completed',
                    'date' => $tx->created_at,
                    'icon' => 'star',
                ];
            });

        // Merge and sort by date
        $activities = $activities
            ->merge($posOrders)
            ->merge($invoices)
            ->merge($payments)
            ->merge($loyaltyTx)
            ->sortByDesc('date')
            ->take($limit)
            ->values();

        return response()->json(['activities' => $activities]);
    }

    /**
     * Recalculate customer statistics
     */
    public function recalculateStats($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $customer->updatePurchaseStats();
        $customer->updateOutstanding();

        return response()->json([
            'message' => 'Customer statistics recalculated',
            'customer' => $customer->fresh(),
        ]);
    }

    /**
     * Send email to customer
     */
    public function sendEmail(Request $request, $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',

        ]);

        if (! $customer->email) {
            return response()->json([
                'message' => 'Customer does not have an email address',
            ], 422);
        }

        try {
            // Queue the email so it doesn't block the HTTP request
            \Illuminate\Support\Facades\Mail::to($customer->email, $customer->name)
                ->queue(new \App\Mail\CustomerEmail($validated['subject'], $validated['body']));

            // Log the activity
            $customer->update([
                'last_interaction_at' => now(),
            ]);

            return response()->json([
                'message' => 'Email queued successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to queue customer email', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send email. Please check your mail configuration.',
            ], 500);
        }
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $customers = Customer::whereIn('id', $ids)->get();
        $deletedIds = [];
        $skipped = [];

        foreach ($customers as $customer) {
            $hasInvoices = \App\Models\Invoice::where('customer_id', $customer->id)->exists();
            $hasQuotations = \App\Models\Quotation::where('customer_id', $customer->id)->exists();

            if ($hasInvoices || $hasQuotations) {
                $skipped[] = ['id' => $customer->id, 'reason' => 'Customer has associated invoices or quotations'];
                continue;
            }

            try {
                $customer->delete();
                $deletedIds[] = $customer->id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $customer->id, 'reason' => $e->getMessage()];
            }
        }

        $notFoundIds = array_values(array_diff($ids, $customers->pluck('id')->toArray()));
        foreach ($notFoundIds as $notFoundId) {
            $skipped[] = ['id' => $notFoundId, 'reason' => 'Not found'];
        }

        return response()->json([
            'message' => count($deletedIds) . ' customer(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }
}
