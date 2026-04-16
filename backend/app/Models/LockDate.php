<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lock Date - Prevents modifications to transactions before a certain date
 */
class LockDate extends Model
{
    use HasFactory;

    protected $fillable = [
        'lock_type',
        'lock_date',
        'set_by',
        'set_at',
        'reason',
        'is_active',
    ];

    protected $casts = [
        'lock_date' => 'date',
        'set_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user who set the lock date
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * Get all overrides for this lock date
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(LockDateOverride::class);
    }

    /**
     * Check if a date is locked
     */
    public static function isDateLocked(string $lockType, $date): bool
    {
        $lockDate = static::where('lock_type', $lockType)
            ->where('is_active', true)
            ->orderBy('lock_date', 'desc')
            ->first();

        if (!$lockDate) {
            // Check for 'all' type lock
            $lockDate = static::where('lock_type', 'all')
                ->where('is_active', true)
                ->orderBy('lock_date', 'desc')
                ->first();
        }

        if (!$lockDate) {
            return false;
        }

        // Use lte (<=) to match LockDateValidationService::validateTransactionDate()
        // which already uses $date->lte($lockDate->lock_date). A date exactly equal
        // to the lock date IS locked — it should not be postable.
        return $date <= $lockDate->lock_date;
    }

    /**
     * Scope to get only active lock dates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get lock dates by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('lock_type', $type);
    }
}
