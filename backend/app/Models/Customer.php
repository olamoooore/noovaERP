<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Modules\POS\Models\PosOrder;

class Customer extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        // Basic Info
        'type',
        'code',
        'name',
        'email',
        'password',
        'phone',
        'mobile',
        'company',
        'tax_number',
        'company_registration_number',
        'website',
        'language',

        // Addresses (legacy - use customer_addresses for multiple)
        'billing_address',
        'shipping_address',

        // Financial
        'credit_limit',
        'payment_terms',
        'total_outstanding',
        'price_list_id',

        // Personal (for individuals)
        'date_of_birth',
        'gender',

        // Relationships
        'parent_id',
        'salesperson_id',

        // Preferences
        'preferred_contact',
        'marketing_opt_in',

        // Stats
        'customer_since',
        'last_interaction_at',
        'total_purchases',
        'total_orders',
        'average_order_value',

        // Status
        'is_active',
        'notes',
    ];

    protected $casts = [
        'type' => 'string',
        'credit_limit' => 'decimal:2',
        'total_outstanding' => 'decimal:2',
        'total_purchases' => 'decimal:2',
        'average_order_value' => 'decimal:2',
        'is_active' => 'boolean',
        'marketing_opt_in' => 'boolean',
        'date_of_birth' => 'date',
        'customer_since' => 'date',
        'last_interaction_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    protected $appends = ['display_name', 'loyalty_points'];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    /**
     * Parent company (for contacts)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'parent_id');
    }

    /**
     * Child contacts (for companies)
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Customer::class, 'parent_id');
    }

    /**
     * Customer contacts (separate table)
     */
    public function customerContacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * Customer addresses
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * Default billing address
     */
    public function defaultBillingAddress()
    {
        return $this->addresses()->where('type', 'billing')->where('is_default', true)->first();
    }

    /**
     * Default shipping address
     */
    public function defaultShippingAddress()
    {
        return $this->addresses()->where('type', 'shipping')->where('is_default', true)->first();
    }

    /**
     * Categories/Tags
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CustomerCategory::class, 'customer_category');
    }

    /**
     * Assigned salesperson
     */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    /**
     * Price list
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(CustomerPriceList::class, 'price_list_id');
    }

    /**
     * Loyalty balances
     */
    public function loyaltyBalances(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyBalance::class);
    }

    /**
     * Loyalty transactions
     */
    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyTransaction::class);
    }

    /**
     * Loyalty module enrollment (hasOne relationship)
     */
    public function customerLoyalty(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Loyalty\Models\CustomerLoyalty::class, 'customer_id');
    }

    /**
     * Invoices
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Payments
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * POS Orders
     */
    public function posOrders(): HasMany
    {
        return $this->hasMany(PosOrder::class);
    }

    /**
     * Web Orders from Ecommerce
     */
    public function webOrders(): HasMany
    {
        return $this->hasMany(\Modules\Ecommerce\Models\WebOrder::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(\Modules\Sales\Models\SalesOrder::class);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    /**
     * Display name (name + company if applicable)
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->type === 'company' && $this->company) {
            return $this->company;
        }

        return $this->name.($this->company ? " ({$this->company})" : '');
    }

    /**
     * Get current loyalty points
     */
    public function getLoyaltyPointsAttribute(): int
    {
        // First check the Loyalty module's CustomerLoyalty model
        $customerLoyalty = $this->customerLoyalty;
        if ($customerLoyalty) {
            return $customerLoyalty->current_points ?? 0;
        }

        // Fallback to legacy loyaltyBalances relationship
        return $this->loyaltyBalances()->sum('points_balance') ?? 0;
    }

    // ==========================================
    // METHODS
    // ==========================================

    /**
     * Update outstanding balance from invoices
     */
    public function updateOutstanding(): void
    {
        $this->total_outstanding = $this->invoices()
            ->whereIn('status', ['posted', 'sent', 'overdue', 'partial'])
            ->sum('balance');
        $this->save();
    }

    /**
     * Update purchase statistics
     */
    public function updatePurchaseStats(): void
    {
        // POS orders use 'paid' status, not 'completed'
        $posOrderCount = $this->posOrders()->where('status', 'paid')->where('is_return', false)->count();
        $invoiceCount = $this->invoices()->whereIn('status', ['sent', 'paid'])->count();
        $this->total_orders = $posOrderCount + $invoiceCount;

        $posTotal = $this->posOrders()->where('status', 'paid')->where('is_return', false)->sum('total');
        $invoiceTotal = $this->invoices()->whereIn('status', ['sent', 'paid'])->sum('total');
        $this->total_purchases = $posTotal + $invoiceTotal;

        $this->average_order_value = $this->total_orders > 0
            ? $this->total_purchases / $this->total_orders
            : 0;

        $this->last_interaction_at = now();
        $this->save();
    }

    /**
     * @deprecated Legacy loyalty methods removed. Use Modules\Loyalty\Services\PointsEarningService
     * and Modules\Loyalty\Services\RedemptionService instead.
     *
     * Migration: All loyalty operations must go through canonical Loyalty module services.
     * - For earning points: app(PointsEarningService::class)->awardPoints(...)
     * - For redeeming points: app(RedemptionService::class)->redeemForDiscount(...)
     */

    /**
     * Get customer's special price for a product
     */
    public function getProductPrice(Product $product): ?float
    {
        if (! $this->price_list_id) {
            return null;
        }

        $priceListItem = CustomerPriceListItem::where('price_list_id', $this->price_list_id)
            ->where('product_id', $product->id)
            ->first();

        if ($priceListItem) {
            return $priceListItem->price;
        }

        // Apply general price list discount
        $priceList = $this->priceList;
        if ($priceList && $priceList->discount_value > 0) {
            $basePrice = $product->price;
            if ($priceList->discount_type === 'percentage') {
                return $basePrice * (1 - $priceList->discount_value / 100);
            }

            return max(0, $basePrice - $priceList->discount_value);
        }

        return null;
    }

    /**
     * Return the customer's primary legacy address.
     */
    public function getFormattedAddressAttribute(): string
    {
        return $this->billing_address ?? '';
    }

    /**
     * Generate unique customer code
     */
    public static function generateCode(string $prefix = 'CUST'): string
    {
        $lastCustomer = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderBy('code', 'desc')
            ->first();

        if ($lastCustomer && preg_match('/(\d+)$/', $lastCustomer->code, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return $prefix.str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($customer) {
            if ($customer->credit_limit === null) {
                $customer->credit_limit = 0;
            }

            if ($customer->payment_terms === null) {
                $customer->payment_terms = 30;
            }

            if ($customer->total_outstanding === null) {
                $customer->total_outstanding = 0;
            }

            if ($customer->is_active === null) {
                $customer->is_active = true;
            }

            if ($customer->marketing_opt_in === null) {
                $customer->marketing_opt_in = false;
            }
        });

        static::creating(function ($customer) {
            if (empty($customer->code)) {
                $customer->code = static::generateCode();
            }
            // Only set customer_since if the column exists on the current DB
            try {
                if (\Schema::connection($customer->getConnectionName())->hasColumn('customers', 'customer_since')) {
                    if (empty($customer->customer_since)) {
                        $customer->customer_since = now()->toDateString();
                    }
                }
            } catch (\Exception $e) {
                // Column doesn't exist, skip
            }
        });
    }
}
