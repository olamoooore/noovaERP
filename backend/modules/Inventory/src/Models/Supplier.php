<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Product;

class Supplier extends Model
{
    use SoftDeletes;

    protected $appends = [
        'contact_person',
        'address',
        'tax_number',
        'payment_terms',
    ];

    protected $fillable = [
        'name',
        'code',
        'contact_name',
        'contact_person', // Keep for backward compatibility
        'email',
        'phone',
        'website',
        'address',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'tax_id',
        'tax_number', // Keep for backward compatibility
        'payment_terms',
        'payment_terms_days',
        'credit_limit',
        'currency',
        'default_lead_time_days',
        'minimum_order_value',
        'quality_rating',
        'delivery_rating',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'payment_terms' => 'integer',
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:2',
        'minimum_order_value' => 'decimal:2',
        'quality_rating' => 'decimal:2',
        'delivery_rating' => 'decimal:2',
        'default_lead_time_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_suppliers')
            ->withPivot([
                'supplier_sku', 'supplier_barcode', 'cost', 'last_cost',
                'lead_time_days', 'moq', 'order_multiple', 'priority',
                'is_preferred', 'last_purchase_date', 'notes', 'is_active'
            ])
            ->withTimestamps();
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return $this->purchaseOrders()
            ->whereIn('status', ['ordered', 'partial', 'received'])
            ->sum('total');
    }

    /**
     * Generate a unique supplier code from name
     */
    public static function generateCode(string $name): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($name, 0, 5)));
        $base = $base ?: 'SUP';
        
        $count = static::where('code', 'like', $base . '%')->count();
        
        return $base . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get contact name (supports both old and new field names)
     */
    public function getContactNameAttribute($value)
    {
        return $value ?: $this->contact_person;
    }

    public function setContactPersonAttribute($value): void
    {
        $this->attributes['contact_name'] = $value;
    }

    public function getContactPersonAttribute(): ?string
    {
        return $this->attributes['contact_name'] ?? null;
    }

    public function setAddressAttribute($value): void
    {
        $this->attributes['address_line_1'] = $value;
    }

    public function getAddressAttribute(): ?string
    {
        return $this->attributes['address_line_1'] ?? null;
    }

    public function setTaxNumberAttribute($value): void
    {
        $this->attributes['tax_id'] = $value;
    }

    public function getTaxNumberAttribute(): ?string
    {
        return $this->attributes['tax_id'] ?? null;
    }

    public function setPaymentTermsAttribute($value): void
    {
        $this->attributes['payment_terms_days'] = $value;
    }

    public function getPaymentTermsAttribute(): ?int
    {
        return isset($this->attributes['payment_terms_days'])
            ? (int) $this->attributes['payment_terms_days']
            : null;
    }
}
