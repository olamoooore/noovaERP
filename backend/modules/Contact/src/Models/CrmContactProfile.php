<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrmContactProfile extends Model
{
    use HasFactory;

    protected $table = 'crm_contact_profiles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'lead_source', 'lead_status',
        'lead_stage', 'assigned_to', 'contact_score', 'last_contacted_at',
        'next_follow_up', 'industry', 'company_size', 'estimated_value',
        'custom_fields', 'metadata',
    ];

    protected $casts = [
        'last_contacted_at' => 'date',
        'next_follow_up' => 'date',
        'estimated_value' => 'decimal:2',
        'custom_fields' => 'array',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
