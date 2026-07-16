<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-004 §13 — Immutable survey response record.
 */
class SurveyResponse extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_EXCLUDED = 'excluded';

    public const IDENTITY_ANONYMOUS = 'anonymous';

    public const IDENTITY_IDENTIFIED = 'identified';

    public const IDENTITY_KNOWN_CUSTOMER = 'known_customer';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'template_survey_responses';

    protected $fillable = [
        'id',
        'template_id',
        'template_version',
        'scoring_version',
        'answers',
        'identity_policy',
        'respondent_token_id',
        'respondent_email',
        'respondent_user_id',
        'calculated_result',
        'status',
        'idempotency_key',
        'responded_at',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'answers' => 'array',
        'calculated_result' => 'array',
        'responded_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $response) {
            if ($response->id === null) {
                $response->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function respondent()
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }
}
