<?php

namespace Modules\PaymentGateway\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class PaymentGateway extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'gateway_name',
        'gateway_type',
        'publishable_key',
        'api_key',
        'api_secret',
        'webhook_secret',
        'is_active',
        'is_test_mode',
        'config',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_test_mode' => 'boolean',
        'config' => 'array',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
        'webhook_secret',
    ];

    /**
     * Encrypt publishable key before saving
     */
    public function setPublishableKeyAttribute($value)
    {
        if ($value) {
            $this->attributes['publishable_key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Decrypt publishable key when accessing
     */
    public function getPublishableKeyAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt API key before saving
     */
    public function setApiKeyAttribute($value)
    {
        if ($value) {
            $this->attributes['api_key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Decrypt API key when accessing
     */
    public function getApiKeyAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt API secret before saving
     */
    public function setApiSecretAttribute($value)
    {
        if ($value) {
            $this->attributes['api_secret'] = Crypt::encryptString($value);
        }
    }

    /**
     * Decrypt API secret when accessing
     */
    public function getApiSecretAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt webhook secret before saving
     */
    public function setWebhookSecretAttribute($value)
    {
        if ($value) {
            $this->attributes['webhook_secret'] = Crypt::encryptString($value);
        }
    }

    /**
     * Decrypt webhook secret when accessing
     */
    public function getWebhookSecretAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Get the user who created this gateway
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this gateway
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get all transactions for this gateway
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Scope for active gateways only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific gateway type
     */
    public function scopeGateway($query, string $gatewayName)
    {
        return $query->where('gateway_name', $gatewayName);
    }
}
