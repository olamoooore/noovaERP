<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'company',
        'tax_number',
        'company_registration_number',
        'address',
        'billing_address',
        'shipping_address',
        'contact_person',
        'website',
        'payment_terms',
        'total_spending',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'payment_terms' => 'integer',
        'total_spending' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public static function generateCode(string $prefix = 'VEN'): string
    {
        $lastVendor = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderBy('code', 'desc')
            ->first();

        $number = 1;
        if ($lastVendor && preg_match('/(\d+)$/', (string) $lastVendor->code, $matches)) {
            $number = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (Vendor $vendor) {
            if (empty($vendor->code)) {
                $vendor->code = static::generateCode();
            }
        });
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(VendorAddress::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'vendor_id');
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function updateSpending(): void
    {
        $this->total_spending = $this->billPayments()->sum('amount');
        $this->save();
    }
}
