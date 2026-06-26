<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditQuery extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'auditor_id',
        'tenant_id',
        'source_type',
        'source_id',
        'source_context',
        'title',
        'description',
        'severity',
        'status',
        'due_date',
        'assigned_to',
        'responded_at',
        'closed_at',
        'closed_by',
        'closure_notes',
    ];

    protected $casts = [
        'source_context' => 'array',
        'due_date' => 'date',
        'responded_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(AuditQueryResponse::class, 'query_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Auditor::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function latestResponse(): ?AuditQueryResponse
    {
        return $this->responses()->latest()->first();
    }
}
