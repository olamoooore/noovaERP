<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactVisibility extends Model
{
    use HasFactory;

    protected $table = 'contact_visibility';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'visibility_scope',
        'scope_id', 'access_level', 'is_restricted', 'restriction_reason',
        'metadata',
    ];

    protected $casts = [
        'is_restricted' => 'boolean',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
