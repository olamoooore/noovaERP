<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class GameReward extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reward_type',
        'reward_key',
        'name',
        'description',
        'coin_value',
        'voucher_value',
        'voucher_code',
        'voucher_max_uses',
        'voucher_uses_remaining',
        'voucher_expires_at',
        'min_purchase_amount',
        'metadata',
    ];

    protected $casts = [
        'coin_value' => 'decimal:2',
        'voucher_value' => 'decimal:2',
        'voucher_max_uses' => 'integer',
        'voucher_uses_remaining' => 'integer',
        'voucher_expires_at' => 'date',
        'min_purchase_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public const TYPE_COIN = 'coin';

    public const TYPE_VOUCHER = 'voucher';

    public const TYPE_DISCOUNT = 'discount';

    public const TYPE_FREE_SHIPPING = 'free_shipping';

    public static function generateVoucherCode(): string
    {
        return 'VR-'.strtoupper(Str::random(8)).'-'.date('ymd');
    }

    public function isAvailable(): bool
    {
        if ($this->voucher_expires_at && $this->voucher_expires_at->isPast()) {
            return false;
        }
        if ($this->voucher_max_uses > 0 && $this->voucher_uses_remaining <= 0) {
            return false;
        }

        return true;
    }

    public function use(): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }
        if ($this->voucher_max_uses > 0) {
            $this->decrement('voucher_uses_remaining');
        }

        return true;
    }

    public function customerRewards()
    {
        return $this->hasMany(CustomerGameReward::class);
    }
}
