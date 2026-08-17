<?php

namespace App\Models;

use App\Accounting\Enums\DocumentIssueState;
use App\Accounting\Enums\RevisionLineageState;
use Illuminate\Database\Eloquent\Model;

/**
 * Document revision — revision-controlled Finance/commercial documents.
 * ADR-005 §27 — revision architecture with document_issue_state and revision_lineage_state.
 */
class DocumentRevision extends Model
{
    protected $table = 'document_revisions';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'document_uuid',
        'base_document_number',
        'document_type',
        'revision_uuid',
        'revision_sequence',
        'revision_code',
        'revision_display_value',
        'revision_scheme_id',
        'revision_scheme_version',
        'record_version',
        'document_issue_state',
        'revision_lineage_state',
        'effective_revision',
        'correction_instrument',
        'correction_document_type',
        'correction_document_id',
        'source_capability',
        'correlation_id',
        'metadata',
    ];

    protected $casts = [
        'revision_sequence' => 'integer',
        'record_version' => 'integer',
        'effective_revision' => 'boolean',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'superseded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DocumentRevision $model) {
            $model->document_issue_state ??= DocumentIssueState::DRAFT->value;
            $model->revision_lineage_state ??= RevisionLineageState::CURRENT->value;
            $model->record_version ??= 1;
            if (!$model->revision_uuid) {
                $model->revision_uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });
    }

    // ── Accessors ──────────────────────────────────────

    public function getIssueStateEnumAttribute(): DocumentIssueState
    {
        return DocumentIssueState::from($this->document_issue_state);
    }

    public function getLineageStateEnumAttribute(): RevisionLineageState
    {
        return RevisionLineageState::from($this->revision_lineage_state);
    }

    public function isCurrent(): bool
    {
        return $this->revision_lineage_state === RevisionLineageState::CURRENT->value;
    }

    public function isIssued(): bool
    {
        return $this->document_issue_state === DocumentIssueState::ISSUED->value;
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeCurrent($query)
    {
        return $query->where('revision_lineage_state', RevisionLineageState::CURRENT->value);
    }

    public function scopeIssued($query)
    {
        return $query->where('document_issue_state', DocumentIssueState::ISSUED->value);
    }

    public function scopeForDocument($query, string $documentUuid)
    {
        return $query->where('document_uuid', $documentUuid);
    }

    public function scopeEffective($query)
    {
        return $query->where('effective_revision', true);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ─────────────────────────────────────────

    public static function nextSequence(string $documentUuid): int
    {
        $latest = static::where('document_uuid', $documentUuid)
            ->orderByDesc('revision_sequence')
            ->first();

        return $latest ? $latest->revision_sequence + 1 : 1;
    }
}
