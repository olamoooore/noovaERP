<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class AccountingImportAudit extends Model
{
    protected $table = 'accounting_import_audit';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'batch_id',
        'staging_id',
        'operation',
        'details',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function batch()
    {
        return $this->belongsTo(AccountingImportBatch::class, 'batch_id');
    }

    public function staging()
    {
        return $this->belongsTo(AccountingImportStaging::class, 'staging_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
