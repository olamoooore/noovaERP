<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerGameReward extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'waiting_room_game_session_id',
        'game_reward_id',
        'reward_type',
        'coin_amount',
        'voucher_amount',
        'voucher_code',
        'redeemed_at',
        'expires_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'coin_amount' => 'decimal:2',
        'voucher_amount' => 'decimal:2',
        'redeemed_at' => 'date',
        'expires_at' => 'date',
        'metadata' => 'array',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_EXPIRED = 'expired';

    public function customer()
    {
        return $this->belongsTo(\App\Models\User::class, 'customer_id');
    }

    public function gameSession()
    {
        return $this->belongsTo(WaitingRoomGameSession::class, 'waiting_room_game_session_id');
    }

    public function reward()
    {
        return $this->belongsTo(GameReward::class, 'game_reward_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return true;
        }

        return false;
    }

    public function markAsRedeemed(): void
    {
        $this->status = self::STATUS_REDEEMED;
        $this->redeemed_at = now();
        $this->save();
    }

    public function markAsExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
        $this->save();
    }
}
