<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactRelationship extends Model
{
    use HasFactory;

    protected $table = 'contact_relationships';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'related_contact_id',
        'relationship_type', 'relationship_label', 'notes',
        'is_primary', 'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }

    public function relatedContact()
    {
        return $this->belongsTo(CoreContact::class, 'related_contact_id');
    }
}
