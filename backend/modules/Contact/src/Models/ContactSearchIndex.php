<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactSearchIndex extends Model
{
    use HasFactory;

    protected $table = 'contact_search_index';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'display_name', 'search_name',
        'email', 'phone', 'mobile', 'company_name', 'roles_text',
        'identifiers_text', 'address_text', 'primary_role',
        'primary_identifier', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
