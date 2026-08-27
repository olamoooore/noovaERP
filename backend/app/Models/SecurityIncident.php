<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityIncident extends Model
{
    protected $table = 'security_incidents';

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'actor_id',
        'target_user_id',
        'tenant_id',
        'ip_address',
        'user_agent',
        'details',
        'severity',
        'status',
        'created_at',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
