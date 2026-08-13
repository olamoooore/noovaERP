<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactTaxonomy extends Model
{
    use HasFactory;

    public const TYPE_FUNCTIONAL_CATEGORY = 'functional_category';

    public const TYPE_PERSISTENT_ROLE = 'persistent_role';

    protected $table = 'contact_taxonomy';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'taxonomy_type', 'code', 'display_label',
        'is_system', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];
}
