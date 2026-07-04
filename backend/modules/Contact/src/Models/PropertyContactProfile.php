<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyContactProfile extends Model
{
    use HasFactory;

    protected $table = 'property_contact_profiles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'contact_role', 'property_id',
        'unit_id', 'lease_start_date', 'lease_end_date', 'monthly_rent',
        'security_deposit', 'payment_schedule', 'metadata',
    ];

    protected $casts = [
        'lease_start_date' => 'date',
        'lease_end_date' => 'date',
        'monthly_rent' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
