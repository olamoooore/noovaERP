<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class BonusScheme extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'scheme_code',
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->scheme_code) {
                $model->scheme_code = static::generateSchemeCode();
            }
        });
    }

    public static function generateSchemeCode(): string
    {
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $number = static::generateSchemeCodeRaw();
            if (! static::withTrashed()->where('scheme_code', $number)->exists()) {
                return $number;
            }
            Log::warning('Bonus scheme code collision, retrying', ['attempt' => $attempt]);
        }
        return 'BNS-' . date('Y') . '-' . date('is');
    }

    private static function generateSchemeCodeRaw(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('bonus_scheme', 'BNS-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            Log::warning('DocumentNumberingService failed for bonus scheme, using fallback');
        }

        $latest = static::withTrashed()
            ->where('scheme_code', 'like', 'BNS-' . date('Y') . '-%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $latest ? (int) substr($latest->scheme_code, -4) + 1 : 1;

        return 'BNS-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    // Status helpers
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isValid(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $now = now();
        if ($this->start_date && $now->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    // Relationships
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(BonusSchemeProduct::class);
    }

    public function customerAssignments(): HasMany
    {
        return $this->hasMany(CustomerBonusScheme::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(BonusSchemeUsage::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeValid($query)
    {
        $now = now();
        return $query->where('status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $now);
            });
    }
}
