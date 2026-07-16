<?php

namespace Modules\POS\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosHeldOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'customer_id',
        'reference',
        'cart_data',
        'subtotal',
        'tax_amount',
        'total',
        'notes',
        'held_at',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'held_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($held) {
            if (empty($held->held_at)) {
                $held->held_at = now();
            }
        });
    }
}
