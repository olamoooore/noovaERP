<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountingImportBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'accounting_import_batches';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'user_id',
        'import_type',
        'file_name',
        'file_hash',
        'total_rows',
        'valid_rows',
        'error_rows',
        'imported_rows',
        'status',
        'mapping_config',
        'error_summary',
        'validated_at',
        'imported_at',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'imported_rows' => 'integer',
        'mapping_config' => 'array',
        'error_summary' => 'array',
        'validated_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    // ─── Constants ───────────────────────────────────────────────

    public const IMPORT_TYPES = [
        'bill' => 'Vendor Bill',
        'invoice' => 'Customer Invoice',
        'journal_entry' => 'Journal Entry',
        'payment' => 'Payment',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'validated' => 'Validated',
        'importing' => 'Importing',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function stagingRows()
    {
        return $this->hasMany(AccountingImportStaging::class, 'batch_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AccountingImportAudit::class, 'batch_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // ─── Methods ────────────────────────────────────────────────

    public function markValidated(): self
    {
        $this->update([
            'status' => 'validated',
            'validated_at' => now(),
        ]);

        return $this;
    }

    public function markImporting(): self
    {
        $this->update(['status' => 'importing']);

        return $this;
    }

    public function markCompleted(int $importedCount): self
    {
        $this->update([
            'status' => 'completed',
            'imported_rows' => $importedCount,
            'imported_at' => now(),
        ]);

        return $this;
    }

    public function markFailed(array $errors): self
    {
        $this->update([
            'status' => 'failed',
            'error_summary' => $errors,
        ]);

        return $this;
    }
}
