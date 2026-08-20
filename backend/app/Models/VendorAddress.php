<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorAddress extends Model
{
    protected $fillable = [
        'vendor_id',
        'type',
        'label',
        'street',
        'street2',
        'city',
        'state',
        'zip',
        'country',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected $appends = ['formatted_address'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function getFormattedAddressAttribute(): string
    {
        return implode(', ', array_filter([
            $this->street,
            $this->street2,
            $this->city,
            $this->state,
            $this->zip,
            $this->country,
        ]));
    }
}
