<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaitingRoomGameSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'queue_session_id',
        'waiting_room_game_id',
        'customer_id',
        'customer_email',
        'play_count',
        'total_score',
        'play_results',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'play_count' => 'integer',
        'total_score' => 'integer',
        'play_results' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(WaitingRoomGame::class, 'waiting_room_game_id');
    }

    public function queueSession()
    {
        return $this->belongsTo(QueueSession::class, 'queue_session_id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Models\User::class, 'customer_id');
    }

    public function rewards()
    {
        return $this->hasMany(CustomerGameReward::class, 'waiting_room_game_session_id');
    }

    public function isActive(): bool
    {
        return $this->completed_at === null;
    }

    public function canPlay(): bool
    {
        if (! $this->isActive()) {
            return false;
        }
        $maxPlays = $this->game?->max_plays_per_session ?? 3;

        return $this->play_count < $maxPlays;
    }

    public function recordPlay(array $result, int $score): void
    {
        $results = $this->play_results ?? [];
        $results[] = $result;
        $this->play_results = $results;
        $this->total_score += $score;
        $this->play_count++;
        if (! $this->started_at) {
            $this->started_at = now();
        }
        $this->save();
    }

    public function complete(): void
    {
        $this->completed_at = now();
        $this->save();
    }
}
