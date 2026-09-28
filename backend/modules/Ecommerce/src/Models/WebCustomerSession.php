<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebCustomerSession extends Model
{
    use HasFactory;

    protected $table = 'web_customer_sessions';

    /**
     * The connection name for the model.
     * Uses the default connection which is switched to tenant schema by tenancy middleware.
     */
    protected $connection = null; // Uses default connection (switched to tenant by middleware)

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
