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
        'id', 'tenant_id', 'batch_id', 'row_number', 'source_record_key',
        'source_payload_hash', 'raw_data', 'mapped_data', 'original_legacy_values',
        'status', 'errors', 'matched_contact_id', 'match_type',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'raw_data' => 'array',
        'mapped_data' => 'array',
        'original_legacy_values' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(ContactImportBatch::class, 'batch_id');
    }
}
