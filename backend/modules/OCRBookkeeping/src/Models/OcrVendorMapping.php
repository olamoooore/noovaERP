<?php

namespace Modules\OCRBookkeeping\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OCR Vendor Mapping Model
 *
 * Stores learned vendor name mappings for intelligent suggestions
 * Improves accuracy over time through usage tracking
 */
class OcrVendorMapping extends Model
{
    use HasFactory;

    protected $table = 'ocr_vendor_mappings';

    protected $fillable = [
        'extracted_name',
        'normalized_name',
        'vendor_id',
        'confidence_score',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    protected $attributes = [
        'usage_count' => 1,
    ];

    /**
     * Boot method to set last_used_at on creation
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->last_used_at)) {
                $model->last_used_at = now();
            }
        });
    }

    /**
     * Relationships
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'vendor_id');
    }

    /**
     * Scopes
     */

    /**
     * Find mapping by normalized name
     */
    public function scopeByNormalizedName($query, string $normalizedName)
    {
        return $query->where('normalized_name', $normalizedName);
    }

    /**
     * Find mappings by vendor
     */
    public function scopeByVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Order by most frequently used
     */
    public function scopeMostUsed($query)
    {
        return $query->orderBy('usage_count', 'desc');
    }

    /**
     * Order by most recently used
     */
    public function scopeRecentlyUsed($query)
    {
        return $query->orderBy('last_used_at', 'desc');
    }

    /**
     * Helper Methods
     */

    /**
     * Increment usage count and update last used timestamp
     */
    public function recordUsage(): bool
    {
        return $this->update([
            'usage_count' => $this->usage_count + 1,
            'last_used_at' => now(),
        ]);
    }

    /**
     * Update confidence score
     *
     * @param  float  $score  The new confidence score (0-1)
     */
    public function updateConfidence(float $score): bool
    {
        if ($score < 0 || $score > 1) {
            throw new \InvalidArgumentException('Confidence score must be between 0 and 1');
        }

        return $this->update([
            'confidence_score' => $score,
        ]);
    }

    /**
     * Check if this mapping has high confidence
     */
    public function isHighConfidence(): bool
    {
        return $this->confidence_score !== null && $this->confidence_score >= 0.85;
    }

    /**
     * Static helper to normalize vendor name
     *
     * @param  string  $name  The vendor name to normalize
     * @return string The normalized name
     */
    public static function normalizeName(string $name): string
    {
        // Convert to lowercase
        $normalized = strtolower($name);

        // Remove common business suffixes
        $suffixes = [
            ' inc', ' inc.', ' incorporated',
            ' llc', ' l.l.c.', ' l.l.c',
            ' ltd', ' ltd.', ' limited',
            ' corp', ' corp.', ' corporation',
            ' co', ' co.', ' company',
            ' pte', ' pte.', ' pte ltd', ' pte. ltd.',
            ' sdn bhd', ' sdn. bhd.',
        ];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                $normalized = substr($normalized, 0, -strlen($suffix));
                break;
            }
        }

        // Remove special characters except spaces
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);

        // Remove extra spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Trim
        $normalized = trim($normalized);

        return $normalized;
    }
}
