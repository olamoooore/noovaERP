<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactImportStaging extends Model
{
    use HasFactory;

    protected $table = 'contact_import_staging';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'batch_id', 'row_number', 'raw_data',
        'mapped_data', 'status', 'errors', 'matched_contact_id', 'match_type',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'raw_data' => 'array',
        'mapped_data' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(ContactImportBatch::class, 'batch_id');
    }
}
