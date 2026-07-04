<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactRole extends Model
{
    use HasFactory;

    protected $table = 'contact_roles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'role', 'role_label',
        'entity_id', 'is_primary', 'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
