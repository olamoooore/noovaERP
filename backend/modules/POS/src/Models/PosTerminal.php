<?php

namespace Modules\POS\Models;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosTerminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'warehouse_id',
        'receipt_header',
        'receipt_footer',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'terminal_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'terminal_id');
    }

    /**
     * Get current open session
     */
    public function currentSession()
    {
        return $this->sessions()->where('status', 'open')->latest()->first();
    }

    /**
     * Check if terminal has an open session
     */
    public function hasOpenSession(): bool
    {
        return $this->sessions()->where('status', 'open')->exists();
    }

    /**
     * Generate unique terminal code
     */
    public static function generateCode(string $prefix = 'POS'): string
    {
        $last = static::where('code', 'like', $prefix.'%')
            ->orderBy('code', 'desc')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->code, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return $prefix.str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $terminal) {
            static::applyDefaultWarehouseId($terminal);
        });

        static::creating(function ($terminal) {
            if (empty($terminal->code)) {
                $terminal->code = static::generateCode();
            }
        });
    }

    private static function applyDefaultWarehouseId(self $terminal): void
    {
        if (! empty($terminal->warehouse_id)) {
            return;
        }

        $defaultWarehouseId = static::resolveDefaultWarehouseId();

        if ($defaultWarehouseId !== null) {
            $terminal->warehouse_id = $defaultWarehouseId;
        }
    }

    public static function resolveDefaultWarehouseId(): ?int
    {
        $configuredWarehouseId = PosSetting::getValue('default_warehouse_id');

        if (! is_numeric($configuredWarehouseId)) {
            return null;
        }

        $warehouseId = (int) $configuredWarehouseId;

        return Warehouse::query()->whereKey($warehouseId)->exists()
            ? $warehouseId
            : null;
    }
}
