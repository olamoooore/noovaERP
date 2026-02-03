<?php

namespace Modules\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflinePaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_gateway_id',
        'method_name',
        'method_code',
        'instructions',
        'account_details',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'account_details' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the parent payment gateway
     */
    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * Scope for active methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('method_name');
    }
}
