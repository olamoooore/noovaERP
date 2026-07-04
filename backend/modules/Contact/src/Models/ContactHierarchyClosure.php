<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactHierarchyClosure extends Model
{
    use HasFactory;

    protected $table = 'contact_hierarchy_closure';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'ancestor_id', 'descendant_id', 'depth',
    ];

    protected $casts = [
        'depth' => 'integer',
    ];
}
