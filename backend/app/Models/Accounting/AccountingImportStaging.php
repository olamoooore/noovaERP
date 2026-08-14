<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingImportStaging extends Model
{
    use HasFactory;

    protected $table = 'accounting_import_staging';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'batch_id',
        'row_number',
        'source_record_key',
        'raw_data',
        'mapped_data',
        'validation_errors',
        'original_legacy_values',
        'status',
        'source_system',
        'source_payload_hash',
        'target_entity_id',
        'target_entity_type',
        'imported_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'mapped_data' => 'array',
        'validation_errors' => 'array',
        'original_legacy_values' => 'array',
        'imported_at' => 'datetime',
    ];

    // ─── Constants ───────────────────────────────────────────────

    public const STATUSES = [
        'pending' => 'Pending',
        'valid' => 'Valid',
        'warning' => 'Warning',
        'error' => 'Error',
        'imported' => 'Imported',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function batch()
    {
        return $this->belongsTo(AccountingImportBatch::class, 'batch_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeValid($query)
    {
        return $query->where('status', 'valid');
    }

    public function scopeForImport($query)
    {
        return $query->whereIn('status', ['valid', 'warning']);
    }

    // ─── Methods ────────────────────────────────────────────────

    public function markValid(): self
    {
        $this->update(['status' => 'valid']);

        return $this;
    }

    public function markWarning(array $warnings): self
    {
        $this->update([
            'status' => 'warning',
            'validation_errors' => $warnings,
        ]);

        return $this;
    }

    public function markError(array $errors): self
    {
        $this->update([
            'status' => 'error',
            'validation_errors' => $errors,
        ]);

        return $this;
    }

    public function markImported(string $entityType, string $entityId): self
    {
        $this->update([
            'status' => 'imported',
            'target_entity_type' => $entityType,
            'target_entity_id' => $entityId,
            'imported_at' => now(),
        ]);

        return $this;
    }
}
