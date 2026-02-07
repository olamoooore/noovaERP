<?php

namespace Modules\Ecommerce\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebCustomerSession extends Model
{
    use HasFactory;

    protected $table = 'web_customer_sessions';

    protected $fillable = [
        'customer_id',
        'token',
        'last_active',
        'expires_at',
    ];

    protected $casts = [
        'last_active' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Relationship to Customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Check if the session is valid (not expired)
     */
    public function isValid(): bool
    {
        return $this->expires_at > now();
    }

    /**
     * Update last active timestamp
     */
    public function updateLastActive(): void
    {
        $this->update(['last_active' => now()]);
    }
}