<?php

namespace Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'job_title',
        'status',
        'source',
        'estimated_value',
        'score',
        'assigned_to',
        'notes',
        'expected_close_date',
        'last_contacted_at',
        'created_by',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'score' => 'integer',
        'expected_close_date' => 'date',
        'last_contacted_at' => 'date',
    ];

    // Simplified pipeline: Lead → Contacted → Interested → Proposal Sent → Won / Lost
    public const STATUSES = ['new', 'contacted', 'interested', 'proposal', 'won', 'lost'];

    public const SOURCES = ['website', 'referral', 'social', 'advertisement', 'cold_call', 'email', 'trade_show', 'other'];

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'lead_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['won', 'lost']);
    }
}
