<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contact_addresses';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'address_type', 'label',
        'line_1', 'line_2', 'line_3', 'city', 'state', 'postal_code',
        'country', 'latitude', 'longitude', 'is_primary',
        'is_default_billing', 'is_default_shipping', 'metadata',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_primary' => 'boolean',
        'is_default_billing' => 'boolean',
        'is_default_shipping' => 'boolean',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }

    public function getFullAddressAttribute(): string
    {
        return collect([$this->line_1, $this->line_2, $this->line_3, $this->city, $this->state, $this->postal_code, $this->country])->filter()->implode(', ');
    }
}
