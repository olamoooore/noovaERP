<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactGroupMember extends Model
{
    use HasFactory;

    protected $table = 'contact_group_members';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'group_id', 'contact_id', 'role_in_group',
        'is_primary', 'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'metadata' => 'array',
    ];

    public function group()
    {
        return $this->belongsTo(ContactGroup::class, 'group_id');
    }

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
