<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductDocument Model
 * Attachments: manuals, certificates, safety sheets
 */
class ProductDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'valid_until',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'valid_until' => 'date',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const TYPE_MANUAL = 'manual';
    public const TYPE_CERTIFICATE = 'certificate';
    public const TYPE_SAFETY_SHEET = 'safety_sheet';
    public const TYPE_DATASHEET = 'datasheet';
    public const TYPE_OTHER = 'other';

    public static function types(): array
    {
        return [
            self::TYPE_MANUAL,
            self::TYPE_CERTIFICATE,
            self::TYPE_SAFETY_SHEET,
            self::TYPE_DATASHEET,
            self::TYPE_OTHER,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get human-readable file size
     */
    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if certificate is expired
     */
    public function isExpired(): bool
    {
        if (!$this->valid_until) {
            return false;
        }
        return $this->valid_until->isPast();
    }

    /**
     * Check if certificate is expiring soon
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if (!$this->valid_until) {
            return false;
        }
        return $this->valid_until->lte(now()->addDays($days)) && !$this->isExpired();
    }
}
