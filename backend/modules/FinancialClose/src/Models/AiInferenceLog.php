<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AiInferenceLog extends Model
{
    use HasUuids;

    protected $table = 'fco_ai_inference_log';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'feature_key',
        'model',
        'input_summary',
        'output_summary',
        'confidence',
        'was_accepted',
        'duration_ms',
        'metadata',
    ];

    protected $casts = [
        'input_summary' => 'array',
        'output_summary' => 'array',
        'confidence' => 'float',
        'was_accepted' => 'boolean',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];
}