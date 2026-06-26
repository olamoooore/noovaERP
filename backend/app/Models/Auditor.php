<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auditor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'phone',
        'organization',
        'invitation_token',
        'status',
        'invited_at',
        'invited_by',
        'access_starts_at',
        'access_ends_at',
        'last_login_at',
        'accepted_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'access_starts_at' => 'datetime',
        'access_ends_at' => 'datetime',
        'last_login_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scopes(): HasMany
    {
        return $this->hasMany(AuditorScope::class);
    }

    public function queries(): HasMany
    {
        return $this->hasMany(AuditQuery::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(AuditExport::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AuditorActivityLog::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();

        if ($this->access_starts_at && $now->lt($this->access_starts_at)) {
            return false;
        }

        if ($this->access_ends_at && $now->gt($this->access_ends_at)) {
            return false;
        }

        return true;
    }

    public function hasExpired(): bool
    {
        if ($this->access_ends_at && now()->gt($this->access_ends_at)) {
            return true;
        }
        return false;
    }
}
