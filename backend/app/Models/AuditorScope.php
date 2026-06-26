<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditorScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'auditor_id',
        'entity_id',
        'accounting_period',
        'modules',
        'reports',
        'transactions',
        'can_export',
        'can_raise_queries',
        'can_view_documents',
    ];

    protected $casts = [
        'modules' => 'array',
        'reports' => 'array',
        'transactions' => 'array',
        'can_export' => 'boolean',
        'can_raise_queries' => 'boolean',
        'can_view_documents' => 'boolean',
    ];

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Auditor::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}
