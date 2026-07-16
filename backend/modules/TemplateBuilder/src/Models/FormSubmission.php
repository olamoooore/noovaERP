<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-004 §13 — Immutable form submission record.
 */
class FormSubmission extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REJECTED = 'rejected';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'template_form_submissions';

    protected $fillable = [
        'id',
        'template_id',
        'template_version',
        'schema_hash',
        'data_contract_version',
        'answers',
        'attachments',
        'consent',
        'submitter_user_id',
        'submitter_token_id',
        'submitter_email',
        'idempotency_key',
        'status',
        'target_record_type',
        'target_record_id',
        'workflow_outcome',
        'rejection_reason',
        'submitted_at',
        'processed_at',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'answers' => 'array',
        'attachments' => 'array',
        'consent' => 'array',
        'workflow_outcome' => 'array',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $submission) {
            if ($submission->id === null) {
                $submission->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitter_user_id');
    }

    public function scopeSubmitted($query)
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_PROCESSED]);
    }
}
