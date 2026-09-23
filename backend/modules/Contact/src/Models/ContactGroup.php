<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactGroup extends Model
{
    use HasFactory;

    protected $table = 'contact_groups';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'group_name', 'group_type',
        'description', 'parent_group_id', 'sort_order',
        // Issue #123: reserved taxonomy codes are system rows; codes added
        // via the tenant API are not.
        'is_system',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_system' => 'boolean',
    ];

    public function members()
    {
        return $this->hasMany(ContactGroupMember::class, 'group_id');
    }

    public function parentGroup()
    {
        return $this->belongsTo(self::class, 'parent_group_id');
    }

    public function childGroups()
    {
        return $this->hasMany(self::class, 'parent_group_id');
    }
}
