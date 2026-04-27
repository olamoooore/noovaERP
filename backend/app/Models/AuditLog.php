<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Models\PosSession;

class AuditLog extends Model
{
    protected $fillable = [
        'operation',
        'reference_type',
        'reference_id',
        'actor_id',
        'terminal_id',
        'session_id',
        'source_route',
        'idempotency_key',
        'before_state',
        'after_state',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Audit logs are immutable - prevent updates
     */
    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \RuntimeException('Audit logs are immutable and cannot be updated');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Audit logs are immutable and cannot be deleted');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    /**
     * Get the reference entity
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }
}
