<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactRegistration extends Model
{
    use HasFactory;

    protected $table = 'contact_registrations';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'registration_type',
        'registration_value', 'issuing_country', 'issued_date',
        'expiry_date', 'is_verified', 'metadata',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expiry_date' => 'date',
        'is_verified' => 'boolean',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
