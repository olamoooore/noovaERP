<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\CustomerContact;
use App\Models\CustomerAddress;
use App\Models\CustomerLoyaltyProgram;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::withCount(['invoices', 'posOrders'])
            ->with(['categories', 'priceList', 'salesperson']);

        // Filters
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
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

        // Sorting
        $sortBy = $request->sort_by ?? 'name';
        $sortDir = $request->sort_dir ?? 'asc';
        $query->orderBy($sortBy, $sortDir);

        $customers = $query->paginate($request->per_page ?? 20);

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Basic
            'type' => 'in:individual,company',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
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
            'contacts.*.email' => 'nullable|email',
            'contacts.*.phone' => 'nullable|string|max:50',
            'contacts.*.mobile' => 'nullable|string|max:50',
            'contacts.*.is_primary' => 'boolean',
            'contacts.*.notes' => 'nullable|string',
        ]);

        // Extract relations
        $categoryIds = $validated['category_ids'] ?? [];
        $addresses = $validated['addresses'] ?? [];
        $contacts = $validated['contacts'] ?? [];
        unset($validated['category_ids'], $validated['addresses'], $validated['contacts']);

        $customer = Customer::create($validated);

        // Attach categories
        if (!empty($categoryIds)) {
            $customer->categories()->attach($categoryIds);
        }

        // Create addresses
        foreach ($addresses as $address) {
            $customer->addresses()->create($address);
        }

        // Create contacts
        foreach ($contacts as $contact) {
            $customer->customerContacts()->create($contact);
        }

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

    public function show(Customer $customer): JsonResponse
    {
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
                'invoices' => fn($q) => $q->latest()->limit(10),
                'posOrders' => fn($q) => $q->latest()->limit(10),
            ])
        );
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            // Basic
            'type' => 'in:individual,company',
            'name' => 'string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
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

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    // ==========================================
    // ADDRESSES
    // ==========================================

    public function addAddress(Request $request, Customer $customer): JsonResponse
    {
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

    public function updateAddress(Request $request, Customer $customer, CustomerAddress $address): JsonResponse
    {
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

    public function deleteAddress(Customer $customer, CustomerAddress $address): JsonResponse
    {
        $address->delete();

        return response()->json(['message' => 'Address deleted']);
    }

    // ==========================================
    // CONTACTS
    // ==========================================

    public function addContact(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'job_title' => 'nullable|string|max:100',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'is_primary' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $contact = $customer->customerContacts()->create($validated);

        return response()->json($contact, 201);
    }

    public function updateContact(Request $request, Customer $customer, CustomerContact $contact): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'job_title' => 'nullable|string|max:100',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'is_primary' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $contact->update($validated);

        return response()->json($contact);
    }

    public function deleteContact(Customer $customer, CustomerContact $contact): JsonResponse
    {
        $contact->delete();

        return response()->json(['message' => 'Contact deleted']);
    }

    // ==========================================
    // LOYALTY
    // ==========================================

    public function loyaltyHistory(Customer $customer): JsonResponse
    {
        $transactions = $customer->loyaltyTransactions()
            ->with('program')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'balance' => $customer->loyalty_points,
            'transactions' => $transactions,
        ]);
    }

    public function adjustLoyalty(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'points' => 'required|integer',
            'notes' => 'nullable|string',
        ]);

        if ($validated['points'] > 0) {
            $customer->addLoyaltyPoints($validated['points'], 'manual', null, $validated['notes']);
        } else {
            $customer->redeemLoyaltyPoints(abs($validated['points']), 'manual', null, $validated['notes']);
        }

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
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%")
              ->orWhere('mobile', 'like', "%{$search}%")
              ->orWhere('company', 'like', "%{$search}%")
              ->orWhere('code', 'like', "%{$search}%");
        })
        ->where('is_active', true)
        ->limit(20)
        ->get(['id', 'code', 'name', 'email', 'phone', 'company', 'type']);

        return response()->json(['customers' => $customers]);
    }
}
