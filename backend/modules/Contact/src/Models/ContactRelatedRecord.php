<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactRelatedRecord extends Model
{
    use HasFactory;

    protected $table = 'contact_related_record_registry';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'module_key', 'record_type',
        'record_id', 'record_label', 'record_amount', 'record_currency',
        'record_date', 'record_status', 'metadata',
    ];

    protected $casts = [
        'record_amount' => 'decimal:2',
        'record_date' => 'date',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
