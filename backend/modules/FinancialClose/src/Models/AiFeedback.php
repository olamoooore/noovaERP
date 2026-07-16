<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    use HasUuids;

    protected $table = 'fci_ai_feedback';

    protected $fillable = [
        'tenant_id',
        'finding_id',
        'user_id',
        'action',
        'comment',
        'edited_output',
    ];

    protected $casts = [
        'edited_output' => 'array',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(AiFinding::class, 'finding_id');
    }
}
