<?php

namespace Modules\Sales\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #152: blanket order / call-off agreement header.
 */
class SalesBlanketOrder extends Model
{
    protected $table = 'sales_blanket_orders';

    protected $fillable = [
        'tenant_id',
        'blanket_number',
        'customer_id',
        'name',
        'status',
        'valid_from',
        'valid_to',
        'currency',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesBlanketOrderLine::class, 'blanket_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActiveOn(\DateTimeInterface|string $date): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $date = \Carbon\Carbon::parse($date);

        if ($this->valid_from && $date->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_to && $date->gt($this->valid_to)) {
            return false;
        }

        return true;
    }

    public static function generateNumber(): string
    {
        return 'BO-'.now()->format('Y').'-'.str_pad((string) (static::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);
    }
}
