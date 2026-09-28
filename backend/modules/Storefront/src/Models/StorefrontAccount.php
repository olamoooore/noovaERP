<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StorefrontAccount extends Model
{
    use SoftDeletes;

    protected $table = 'storefront_accounts';

    protected $fillable = [
        'customer_id', 'email', 'password', 'first_name', 'last_name', 'phone',
        'status', 'email_verified_at', 'last_login_at', 'failed_login_count', 'locked_until',
        'marketing_email_opt_in', 'marketing_sms_opt_in', 'marketing_whatsapp_opt_in',
        'consent_source', 'consent_at', 'locale', 'currency_code',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'consent_at' => 'datetime',
        'failed_login_count' => 'integer',
        'marketing_email_opt_in' => 'boolean',
        'marketing_sms_opt_in' => 'boolean',
        'marketing_whatsapp_opt_in' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(StorefrontSession::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(StorefrontCart::class, 'account_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StorefrontOrder::class, 'account_id');
    }
}
