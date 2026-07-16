<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectContactProfile extends Model
{
    use HasFactory;

    protected $table = 'project_contact_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'contact_role', 'project_id',
        'contract_number', 'contract_value', 'contract_start_date',
        'contract_end_date', 'metadata',
    ];

    protected $casts = [
        'contract_value' => 'decimal:2',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
