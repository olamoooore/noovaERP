<?php

namespace Modules\FinancialReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Carbon\Carbon;

class ReportCache extends Model
{
    use HasFactory;

    protected $table = 'report_cache';

    protected $fillable = [
        'cache_key',
        'report_type',
        'filters',
        'data',
        'metadata',
        'hit_count',
        'last_accessed_at',
        'expires_at',
        'generated_at',
        'user_id',
    ];

    protected $casts = [
        'filters' => 'array',
        'data' => 'array',
        'metadata' => 'array',
        'last_accessed_at' => 'datetime',
        'expires_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scopes
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeByReportType($query, string $reportType)
    {
        return $query->where('report_type', $reportType);
    }

    public function scopeByCacheKey($query, string $cacheKey)
    {
        return $query->where('cache_key', $cacheKey);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Methods
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function incrementHitCount(): void
    {
        $this->increment('hit_count');
        $this->update(['last_accessed_at' => now()]);
    }

    public function getData(): array
    {
        $this->incrementHitCount();
        return $this->data;
    }

    /**
     * Static methods
     */
    public static function getCached(string $cacheKey): ?self
    {
        return static::valid()
            ->byCacheKey($cacheKey)
            ->first();
    }

    public static function store(
        string $cacheKey,
        string $reportType,
        array $filters,
        array $data,
        int $ttl = 3600,
        ?int $userId = null,
        ?array $metadata = null
    ): self {
        return static::updateOrCreate(
            ['cache_key' => $cacheKey],
            [
                'report_type' => $reportType,
                'filters' => $filters,
                'data' => $data,
                'metadata' => $metadata,
                'expires_at' => now()->addSeconds($ttl),
                'generated_at' => now(),
                'user_id' => $userId,
                'hit_count' => 0,
            ]
        );
    }

    public static function clearExpired(): int
    {
        return static::expired()->delete();
    }

    public static function clearByReportType(string $reportType): int
    {
        return static::byReportType($reportType)->delete();
    }

    public static function clearAll(): int
    {
        return static::query()->delete();
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\FinancialReporting\Database\Factories\ReportCacheFactory::new();
    }
}
