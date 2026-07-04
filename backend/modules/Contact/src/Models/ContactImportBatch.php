<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactImportBatch extends Model
{
    use HasFactory;

    protected $table = 'contact_import_batches';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'user_id', 'file_name', 'file_hash',
        'total_rows', 'valid_rows', 'error_rows', 'duplicate_rows',
        'status', 'error_summary', 'mapping_config', 'imported_at',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'mapping_config' => 'array',
        'imported_at' => 'datetime',
    ];

    public function stagingRecords()
    {
        return $this->hasMany(ContactImportStaging::class, 'batch_id');
    }
}

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
