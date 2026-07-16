<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaitingRoomGame extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'game_key',
        'name',
        'game_type',
        'description',
        'game_config',
        'reward_config',
        'play_duration_seconds',
        'max_plays_per_session',
        'is_active',
    ];

    protected $casts = [
        'game_config' => 'array',
        'reward_config' => 'array',
        'play_duration_seconds' => 'integer',
        'max_plays_per_session' => 'integer',
        'is_active' => 'boolean',
    ];

    public function sessions()
    {
        return $this->hasMany(WaitingRoomGameSession::class);
    }

    public function activeSessions()
    {
        return $this->hasMany(WaitingRoomGameSession::class)->whereNull('completed_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getRewardTiersAttribute(): array
    {
        return $this->reward_config['tiers'] ?? [];
    }
}
