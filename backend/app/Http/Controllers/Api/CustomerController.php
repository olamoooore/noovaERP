<?php

namespace App\Http\Controllers\Api;

use App\Exports\CustomersExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Services\CanonicalCustomerService;
use App\Services\CustomerAddressResolver;
use App\Services\CustomerContactSyncService;
use App\Services\CustomerDuplicateService;
use App\Services\CustomerMergeService;
use App\Services\CustomerWideSearchService;
use App\Services\EntityAccessService;
use App\Services\TagService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;

class CustomerController extends Controller
{
    private const NUMERIC_PHONE_RULE = 'nullable|regex:/^[0-9]+$/|max:50';

    /**
     * Customer columns mirrored onto the canonical Contact Master contact
     * (issue #432 / CU4). Editing any of these must refresh the contact.
     */
    private const CONTACT_MIRROR_FIELDS = ['name', 'type', 'email', 'phone', 'mobile', 'website'];

    /**
     * Issue #439 (CU11): the only columns GET /customers may be ordered by.
     * These are real `customers` columns (no relations/aggregates), covering
     * the columns the customer list UI displays and exports.
     */
    private const SORTABLE_COLUMNS = [
        'id',
        'name',
        'code',
        'email',
        'phone',
        'mobile',
        'company',
        'tax_number',
        'type',
        'credit_limit',
        'payment_terms',
        'total_outstanding',
        'total_purchases',
        'total_orders',
        'customer_since',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * Issue #434 (CU6): scope a customer query to the requester's accessible
     * legal entities. Mirrors SalesOrderController::applyEntityScope(): users
     * with no entity grants keep the historical unscoped view (fail-open);
     * shared customers (legal_entity_id = NULL) stay visible to scoped users.
     */
    private function applyEntityScope(Request $request, Builder $query): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $entityIds = app(EntityAccessService::class)->accessibleEntityIds((int) $user->id);

        if ($entityIds !== []) {
            $query->accessibleBy($entityIds);
        }
    }

    /**
     * Issue #434 (CU6): resolve a customer inside the requester's entity
     * scope. Out-of-scope or missing ids return null -> the caller answers
     * 404 (same behaviour as SalesOrderController::findScopedOrder()).
     */
    private function findScopedCustomer(Request $request, $id): ?Customer
    {
        $query = Customer::query();
        $this->applyEntityScope($request, $query);

        return $query->find($id);
    }

    private function customerNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Customer not found'], 404);
    }

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

        $this->applyEntityScope($request, $query);

        // Filters — Odoo-style wide search (see CustomerWideSearchService).
        $search = $request->filled('search') ? (string) $request->search : null;
        if ($search !== null) {
            app(CustomerWideSearchService::class)->apply($query, $search);

            $query->with([
                'customerContacts' => fn ($contact) => $contact->select('id', 'customer_id', 'name', 'job_title', 'contact_category', 'contact_type', 'is_primary'),
                'addresses' => fn ($address) => $address->select('id', 'customer_id', 'type', 'label', 'street', 'street2', 'city', 'state', 'zip', 'country', 'is_default'),
            ]);
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
            // Issue #438 (CU10): "has balance" must mean a real open
            // receivable, not merely "not paid and not draft" — that old test
            // also matched voided/reversed/superseded/cancelled and fully
            // credited invoices whose remaining balance is zero. Mirror the AR
            // context/aging definition (AgedReceivablesService::
            // getCustomerContext) so this filter can never disagree with the
            // figures the user is shown.
            $query->whereHas('invoices', function ($q) {
                $q->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
                    ->where('balance', '>', 0)
                    ->notSuperseded();
            });
        }

        // Filter by document tag.
        if ($request->filled('tag')) {
            $taggedIds = app(\App\Services\TagService::class)
                ->subjectIdsForTagSlugs([$request->tag], 'customer');

            $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }

        // Sorting — issue #439 (CU11): validate against an allow-list first,
        // so an unknown column or direction is a 422 validation error instead
        // of an unhandled SQL/InvalidArgumentException (500).
        $sort = $request->validate([
            'sort_by' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);
        $query->orderBy($sort['sort_by'] ?? 'name', $sort['sort_dir'] ?? 'asc');

        if ($request->boolean('ids_only')) {
            $ids = $query->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $customers = $query->paginate($request->per_page ?? 20);

        // Match context (contacts / tags / addresses) for the search results,
        // so the picker can show WHY each customer matched.
        $tagMatches = $search !== null
            ? app(\App\Services\TagService::class)->matchingTagNames(
                $customers->getCollection()->pluck('id')->map('intval')->all(),
                'customer',
                $search,
            )
            : [];

        // Transform data to include loyalty info for POS
        $customers->getCollection()->transform(function ($customer) use ($search, $tagMatches) {
            $loyalty = $customer->customerLoyalty;
            $customer->current_points = $loyalty?->current_points ?? 0;
            $customer->loyalty_tier_name = $loyalty?->tier?->name;
            $customer->is_enrolled = $loyalty !== null;

            if ($search !== null) {
                $needle = mb_strtolower($search);

                if ($customer->relationLoaded('customerContacts')) {
                    $customer->matched_contacts = $customer->customerContacts
                        ->filter(fn ($contact) => $contact->name && str_contains(mb_strtolower($contact->name), $needle))
                        ->pluck('name')
                        ->take(3)
                        ->values();
                    unset($customer->customerContacts);
                }

                if ($customer->relationLoaded('addresses')) {
                    // "Building name" style labels plus street/city text.
                    $customer->matched_addresses = $customer->addresses
                        ->map(function ($address) {
                            $parts = [$address->label, $address->street, $address->street2, $address->city, $address->state, $address->zip, $address->country];

                            return collect($parts)->filter()->implode(', ');
                        })
                        ->filter(fn ($text) => $text && str_contains(mb_strtolower($text), $needle))
                        ->take(2)
                        ->values();
                    unset($customer->addresses);
                }

                $customer->matched_tags = $tagMatches[$customer->id] ?? [];
            }

            return $customer;
        });

        return response()->json($customers);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(CustomersExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(CustomersExport::COLUMNS))],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'distinct', 'exists:customers,id'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        $query = Customer::query();
        $this->applyEntityScope($request, $query);
        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids'));
        }
        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%")->orWhere('company', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('category_id')) {
            $query->whereHas('categories', fn ($q) => $q->where('customer_categories.id', $request->category_id));
        }
        if ($request->filled('tag')) {
            $taggedIds = app(TagService::class)
                ->subjectIdsForTagSlugs([$request->tag], 'customer');

            $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }

        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new CustomersExport($query->orderBy('name'), $validated['columns'], $format),
            'customers_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    public function store(
        Request $request,
        CanonicalCustomerService $canonicalCustomers,
        CustomerDuplicateService $duplicates,
    ): JsonResponse {
        $validated = $request->validate([
            // Basic
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'code')->whereNull('deleted_at'),
            ],
            'type' => 'in:individual,company',
            'buyer_type' => 'nullable|in:business,individual,government,foreign',
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
            'price_list_id' => 'nullable|integer',

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

            // Issue #435 (CU7): tiered duplicate acknowledgement. A medium
            // match needs a reason; a strong match needs the explicit override.
            'duplicate_acknowledgement_reason' => 'nullable|string|max:500',
            'allow_duplicate' => 'boolean',

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

        // Issue #435 (CU7): tiered duplicate detection replaces the old
        // all-or-nothing email/phone rejection.
        $duplicateInspection = $duplicates->inspect($validated);
        if ($blocked = $duplicateInspection->blockedResponse()) {
            return $blocked;
        }

        $allowDuplicate = (bool) ($validated['allow_duplicate'] ?? false);
        $acknowledgementReason = isset($validated['duplicate_acknowledgement_reason'])
            ? (string) $validated['duplicate_acknowledgement_reason']
            : null;

        $validated['price_list_id'] = Customer::normalizePriceListId($validated['price_list_id'] ?? null);

        // Extract relations
        $categoryIds = $validated['category_ids'] ?? [];
        $addresses = $validated['addresses'] ?? [];
        $contacts = $validated['contacts'] ?? [];
        unset(
            $validated['category_ids'],
            $validated['addresses'],
            $validated['contacts'],
            $validated['allow_duplicate'],
            $validated['duplicate_acknowledgement_reason'],
        );

        // Issue #433 (CU5): customer creation goes through the single canonical
        // service (SOP §4). It wraps customer + Contact Master organisation
        // contact + related records in one transaction, so a failure rolls the
        // whole operation back — no partial state.
        //
        // Issue #440 (CU12): the canonical service also initialises a zero
        // balance for every active loyalty programme inside that same
        // transaction.
        $customer = $canonicalCustomers->create($validated, [
            'category_ids' => $categoryIds,
            'addresses' => $addresses,
            'contacts' => $contacts,
        ]);

        // Issue #435 (CU7): record the collisions a human accepted, so the
        // duplicate review queue can see them.
        $duplicates->recordAcknowledgedMatches($customer, $duplicateInspection, $allowDuplicate, $acknowledgementReason);

        // Issue #440 (CU12): loyalty balances are initialised inside the
        // canonical service's transaction for every active programme — not
        // here after the commit.
        return response()->json(
            $customer->load(['categories', 'addresses', 'customerContacts', 'loyaltyBalances']),
            201
        );
    }

    public function show(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        ]);

        // ADR-003B R4: the invoice/quotation screens pre-fill bill-to/ship-to
        // from the same resolver the backend snapshots use.
        return response()->json(array_merge($customer->toArray(), [
            'resolved_addresses' => app(CustomerAddressResolver::class)->resolve($customer),
        ]));
    }

    public function invoices(Request $request, $customer): JsonResponse
    {
        $customerModel = $this->findScopedCustomer($request, $customer);

        if (! $customerModel) {
            return $this->customerNotFound();
        }

        $invoices = $customerModel->invoices()->latest()->paginate($request->integer('per_page', 10));

        return response()->json($invoices);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
            'buyer_type' => 'nullable|in:business,individual,government,foreign',
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

            // ADR-003B W1: the edit form's structured billing fields. They
            // update the default billing address — the record the form loads
            // and new documents read — not only the legacy text column.
            'billing_address_record' => 'nullable|array',
            'billing_address_record.street' => 'nullable|string|max:255',
            'billing_address_record.street2' => 'nullable|string|max:255',
            'billing_address_record.city' => 'nullable|string|max:100',
            'billing_address_record.state' => 'nullable|string|max:100',
            'billing_address_record.zip' => 'nullable|string|max:20',
            'billing_address_record.country' => 'nullable|string|max:100',

            // Financial
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:0',
            'price_list_id' => 'nullable|integer',

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

            // Issue #435 (CU7): see store().
            'duplicate_acknowledgement_reason' => 'nullable|string|max:500',
            'allow_duplicate' => 'boolean',
        ]);

        $validated = $this->normalizeCustomerIdentityFields($validated);

        // Issue #435 (CU7): tiered duplicate detection replaces the old
        // all-or-nothing email/phone rejection.
        $duplicateInspection = app(CustomerDuplicateService::class)->inspect($validated, $customer);
        if ($blocked = $duplicateInspection->blockedResponse()) {
            return $blocked;
        }

        $allowDuplicate = (bool) ($validated['allow_duplicate'] ?? false);
        $acknowledgementReason = isset($validated['duplicate_acknowledgement_reason'])
            ? (string) $validated['duplicate_acknowledgement_reason']
            : null;

        if (array_key_exists('price_list_id', $validated)) {
            $validated['price_list_id'] = Customer::normalizePriceListId($validated['price_list_id']);
        }

        // Extract category IDs
        $categoryIds = $validated['category_ids'] ?? null;
        $billingAddressRecord = $validated['billing_address_record'] ?? null;
        unset(
            $validated['category_ids'],
            $validated['billing_address_record'],
            $validated['allow_duplicate'],
            $validated['duplicate_acknowledgement_reason'],
        );

        // Issue #432 (CU4): the customer profile and its canonical Contact
        // Master contact are edited in the same transaction. Without this the
        // Contact Master keeps the pre-edit name/email/phone/website forever.
        \Illuminate\Support\Facades\DB::transaction(function () use ($customer, $validated, $categoryIds, $billingAddressRecord) {
            $customer->update($validated);

            // Sync categories if provided
            if ($categoryIds !== null) {
                $customer->categories()->sync($categoryIds);
            }

            if ($billingAddressRecord !== null) {
                $this->saveDefaultBillingAddress($customer, $billingAddressRecord);
            }

            if (! $customer->core_contact_uuid || $customer->wasChanged(self::CONTACT_MIRROR_FIELDS)) {
                app(CustomerContactSyncService::class)->ensureOrganisationContact($customer);
            }
        });

        // Issue #435 (CU7): record the collisions a human accepted.
        app(CustomerDuplicateService::class)->recordAcknowledgedMatches(
            $customer,
            $duplicateInspection,
            $allowDuplicate,
            $acknowledgementReason,
        );

        return response()->json(
            $customer->load(['categories', 'addresses', 'customerContacts', 'priceList', 'salesperson'])
        );
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

        // Issue #170 (CRITICAL): port the same dependency guard bulkDestroy()
        // already uses. Soft-deleting a customer that still has invoices or
        // quotations leaves those invoices pointing at a soft-deleted customer,
        // which crashes the AR Aging report (Invoice::with('customer') resolves
        // to null under the soft-delete global scope). Block the delete with a
        // clear 409 instead.
        $blockers = $this->customerDependencyBlockers($customer->id);
        if (! empty($blockers)) {
            return response()->json([
                'message' => 'Customer has associated invoices or quotations',
                'blockers' => $blockers,
            ], 409);
        }

        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    /**
     * Shared dependency guard for customer deletion (issue #170). Returns the
     * list of blocking relationship types, empty when the customer is safe to
     * delete. Used by both destroy() and bulkDestroy() so the single-record and
     * bulk paths can never diverge again.
     *
     * @return string[]
     */
    /**
     * Issue #430 (CU2): every document that references the customer blocks
     * deletion. Checking only invoices and quotations let sales orders, POS and
     * web orders, payments, credit notes, delivery orders, medical cases and
     * loyalty transactions keep pointing at a soft-deleted customer — the same
     * crash class as #170 (AR aging resolving a null customer). Both the single
     * and the bulk delete path use this list.
     */
    private function customerDependencyBlockers(int $customerId): array
    {
        $dependencies = [
            'invoices' => \App\Models\Invoice::class,
            'quotations' => \App\Models\Quotation::class,
            'sales orders' => \Modules\Sales\Models\SalesOrder::class,
            'POS orders' => \Modules\POS\Models\PosOrder::class,
            'web orders' => \Modules\Ecommerce\Models\WebOrder::class,
            'payments' => \App\Models\Payment::class,
            'credit notes' => \App\Models\CustomerCreditNote::class,
            'delivery orders' => \Modules\Sales\Models\DeliveryOrder::class,
            'medical cases' => \App\Models\MedicalCase::class,
            'loyalty transactions' => \App\Models\CustomerLoyaltyTransaction::class,
        ];

        $blockers = [];

        foreach ($dependencies as $label => $model) {
            if ($model::query()->where('customer_id', $customerId)->exists()) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }

    // ==========================================
    // ADDRESSES
    // ==========================================

    public function addAddress(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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

    /**
     * ADR-003B W1: write the edit form's billing fields to the customer's
     * default billing address (the same record the form loads from and new
     * invoices/quotations read), creating it when the customer has none.
     *
     * @param  array<string, string|null>  $fields
     */
    private function saveDefaultBillingAddress(Customer $customer, array $fields): void
    {
        $attributes = [];
        foreach (['street', 'street2', 'city', 'state', 'zip', 'country'] as $field) {
            $attributes[$field] = $fields[$field] ?? null;
        }

        $billing = $customer->addresses()
            ->where('type', 'billing')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($billing) {
            $billing->update($attributes + ['is_default' => true]);

            return;
        }

        $customer->addresses()->create($attributes + ['type' => 'billing', 'is_default' => true]);
    }

    public function updateAddress(Request $request, $customerId, $addressId): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $customerId);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer(request(), $customerId);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer($request, $customerId);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer(request(), $customerId);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer($request, $customerId);

        if (! $customer) {
            return $this->customerNotFound();
        }

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

    public function loyaltyHistory(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer($request, $customer);

        if (! $customer) {
            return $this->customerNotFound();
        }

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

    /**
     * Issue #435 (CU7): merge a duplicate customer into a surviving one.
     *
     * The route-level merge is owned by CustomerMergeService: documents are
     * re-pointed, the merged customer is archived (not deleted) and the
     * ContactMergeHistory row is written through the Contact Master service.
     */
    public function merge(Request $request, $id, CustomerMergeService $merges): JsonResponse
    {
        $primary = $this->findScopedCustomer($request, $id);

        if (! $primary) {
            return $this->customerNotFound();
        }

        $validated = $request->validate([
            'merged_customer_id' => 'required|integer',
            'reason' => 'nullable|string|max:1000',
        ]);

        $merged = $this->findScopedCustomer($request, $validated['merged_customer_id']);

        if (! $merged) {
            return $this->customerNotFound();
        }

        if ((int) $primary->id === (int) $merged->id) {
            throw ValidationException::withMessages([
                'merged_customer_id' => ['A customer cannot be merged into itself.'],
            ]);
        }

        if ($merges->isAlreadyMerged($merged)) {
            throw ValidationException::withMessages([
                'merged_customer_id' => ['This customer has already been merged.'],
            ]);
        }

        $summary = $merges->merge(
            $primary,
            $merged,
            (int) $request->user()->id,
            $validated['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Customer merged successfully',
            'primary_customer_id' => $summary['primary_customer_id'],
            'merged_customer_id' => $summary['merged_customer_id'],
            'moved' => $summary['moved'],
        ]);
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

        $customers = Customer::query();
        $this->applyEntityScope($request, $customers);
        app(CustomerWideSearchService::class)->apply($customers, (string) $search);

        $customers = $customers
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
    public function statistics(Request $request): JsonResponse
    {
        $base = Customer::query();
        $this->applyEntityScope($request, $base);

        $totalCustomers = (clone $base)->count();
        $activeCustomers = (clone $base)->where('is_active', true)->count();
        $companies = (clone $base)->where('type', 'company')->count();
        $individuals = (clone $base)->where('type', 'individual')->count();

        $totalOutstanding = (clone $base)->sum('total_outstanding');
        $totalPurchases = (clone $base)->sum('total_purchases');

        $newThisMonth = (clone $base)->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $topCustomers = (clone $base)->where('is_active', true)
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
     * D2 (Oct-2026 invoice review): compact AR context strip shown while
     * entering an invoice — outstanding, overdue, credit usage, last invoice.
     */
    public function creditContext(Request $request, $id): JsonResponse
    {
        if (! $this->findScopedCustomer($request, $id)) {
            return $this->customerNotFound();
        }

        return response()->json(
            app(\App\Services\AgedReceivablesService::class)->getCustomerContext((int) $id)
        );
    }

    /**
     * D6 (Oct-2026 invoice review): the products this customer buys most
     * often — one-click chips on the invoice entry screen.
     */
    public function frequentProducts(Request $request, $id): JsonResponse
    {
        $query = Customer::withTrashed();
        $this->applyEntityScope($request, $query);

        if (! $query->find($id)) {
            return $this->customerNotFound();
        }

        return response()->json([
            'data' => app(\App\Services\CustomerProductHistoryService::class)->frequentProducts((int) $id),
        ]);
    }

    /**
     * Get individual customer statistics
     */
    public function customerStatistics(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
            ->whereIn('status', Customer::PURCHASE_INVOICE_STATUSES)
            ->notSuperseded()
            ->selectRaw('
                COUNT(*) as total_invoices,
                SUM(total) as total_invoiced,
                SUM(balance) as outstanding_balance,
                SUM(CASE WHEN status = \'paid\' THEN total ELSE 0 END) as paid_amount,
                MAX(invoice_date) as last_invoice_date
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
        // Qualify the columns: the products join also has a `status` column,
        // so a bare `status` is ambiguous (SQLSTATE 42702).
        $favoriteProducts = $customer->posOrders()
            ->where('pos_orders.status', 'paid')
            ->where('pos_orders.is_return', false)
            ->join('pos_order_items', 'pos_orders.id', '=', 'pos_order_items.pos_order_id')
            ->join('products', 'pos_order_items.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.name, SUM(pos_order_items.quantity) as total_qty, SUM(pos_order_items.line_total) as total_spent')
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_qty', 'desc')
            ->limit(5)
            ->get();

        $lastPaymentDate = $customer->payments()->max('payment_date');

        return response()->json([
            'pos_orders' => $posOrderStats,
            'invoices' => $invoiceStats,
            'monthly_trend' => $monthlyTrend,
            'favorite_products' => $favoriteProducts,
            'loyalty_points' => $customer->loyalty_points,
            'customer_since' => $customer->customer_since,
            'last_interaction' => collect([
                $customer->last_interaction_at,
                $posOrderStats?->last_order_date,
                $invoiceStats?->last_invoice_date,
                $lastPaymentDate,
            ])->filter()
                ->map(fn ($date) => \Illuminate\Support\Carbon::parse($date)->toIso8601String())
                ->max(),
        ]);
    }

    /**
     * Get customer activity timeline
     */
    public function activity($id, Request $request): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
    public function recalculateStats(Request $request, $id): JsonResponse
    {
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $customer = $this->findScopedCustomer($request, $id);

        if (! $customer) {
            return $this->customerNotFound();
        }

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
        $scopedQuery = Customer::whereIn('id', $ids);
        $this->applyEntityScope($request, $scopedQuery);
        $customers = $scopedQuery->get();
        $deletedIds = [];
        $skipped = [];

        foreach ($customers as $customer) {
            // Issue #170: use the same shared guard as destroy() so the bulk and
            // single-record paths can never diverge.
            $blockers = $this->customerDependencyBlockers($customer->id);
            if (! empty($blockers)) {
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
            'message' => count($deletedIds).' customer(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }
}
