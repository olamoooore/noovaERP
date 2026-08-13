<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'core_contact_uuid',
        'name',
        'job_title',
        'contact_category',
        'contact_type',
        'department_name',
        'original_legacy_values',
        'email',
        'phone',
        'mobile',
        'street',
        'street2',
        'city',
        'state',
        'zip',
        'country',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'original_legacy_values' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
