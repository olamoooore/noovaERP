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
                'posOrders' => fn($q) => $q->with('items.product:id,name')->latest()->limit(10),
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
    public function customerStatistics(Customer $customer): JsonResponse
    {
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
    public function activity(Customer $customer, Request $request): JsonResponse
    {
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
                    'date' => $invoice->issue_date,
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
                    'description' => ucfirst($payment->method) . ' payment',
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
    public function recalculateStats(Customer $customer): JsonResponse
    {
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
    public function sendEmail(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        if (!$customer->email) {
            return response()->json([
                'message' => 'Customer does not have an email address',
            ], 422);
        }

        try {
            // Send the email using Laravel's Mail facade
            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($customer, $validated) {
                $message->to($customer->email, $customer->name)
                    ->subject($validated['subject'])
                    ->html(nl2br($validated['body']));
            });

            // Log the activity
            $customer->update([
                'last_interaction_at' => now(),
            ]);

            return response()->json([
                'message' => 'Email sent successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to send customer email', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send email. Please check your mail configuration.',
            ], 500);
        }
    }
}
