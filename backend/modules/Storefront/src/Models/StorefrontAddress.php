<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontAddress extends Model
{
    protected $table = 'storefront_account_addresses';

    protected $fillable = [
        'account_id', 'name', 'company', 'phone', 'address1', 'address2',
        'city', 'state', 'postal_code', 'country_code',
        'is_default_billing', 'is_default_shipping',
    ];

    protected $casts = [
        'is_default_billing' => 'boolean',
        'is_default_shipping' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StorefrontAccount::class, 'account_id');
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'company' => $this->company,
            'phone' => $this->phone,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
            'is_default_billing' => $this->is_default_billing,
            'is_default_shipping' => $this->is_default_shipping,
        ];
    }
}
