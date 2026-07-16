<?php

namespace Modules\OCRBookkeeping\Models;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OCR Account Mapping Model
 *
 * Stores learned account mappings based on vendor and description patterns
 * Enables intelligent Chart of Accounts suggestions
 */
class OcrAccountMapping extends Model
{
    use HasFactory;

    protected $table = 'ocr_account_mappings';

    protected $fillable = [
        'vendor_id',
        'description_pattern',
        'keywords',
        'account_id',
        'confidence_score',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'keywords' => 'array',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * Scopes
     */

    /**
     * Find mappings by vendor
     */
    public function scopeByVendor($query, ?int $vendorId)
    {
        if ($vendorId === null) {
            return $query->whereNull('vendor_id');
        }

        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Find mappings by account
     */
    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Find mappings containing specific keywords
     */
    public function scopeWithKeyword($query, string $keyword)
    {
        return $query->whereJsonContains('keywords', strtolower($keyword));
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
     * Order by highest confidence
     */
    public function scopeHighestConfidence($query)
    {
        return $query->orderBy('confidence_score', 'desc');
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
        return $this->confidence_score !== null && $this->confidence_score >= 0.75;
    }

    /**
     * Check if description matches this mapping's pattern
     *
     * @param  string  $description  The description to check
     */
    public function matchesDescription(string $description): bool
    {
        if (! $this->description_pattern) {
            return false;
        }

        // Simple pattern matching (can be enhanced with regex)
        return stripos($description, $this->description_pattern) !== false;
    }

    /**
     * Check if description contains any of the mapping's keywords
     *
     * @param  string  $description  The description to check
     * @return int Number of matching keywords
     */
    public function countMatchingKeywords(string $description): int
    {
        if (! $this->keywords || ! is_array($this->keywords)) {
            return 0;
        }

        $description = strtolower($description);
        $matchCount = 0;

        foreach ($this->keywords as $keyword) {
            if (stripos($description, strtolower($keyword)) !== false) {
                $matchCount++;
            }
        }

        return $matchCount;
    }

    /**
     * Static helper to extract keywords from description
     *
     * @param  string  $description  The description text
     * @return array Array of extracted keywords
     */
    public static function extractKeywords(string $description): array
    {
        // Convert to lowercase
        $text = strtolower($description);

        // Remove special characters
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Common stop words to exclude
        $stopWords = [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
            'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the',
            'to', 'was', 'will', 'with', 'this', 'but', 'they', 'have', 'had',
            'what', 'when', 'where', 'who', 'which', 'why', 'how', 'all', 'each',
            'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such',
        ];

        // Filter out stop words and short words
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && ! in_array($word, $stopWords);
        });

        // Remove duplicates and return
        return array_values(array_unique($keywords));
    }
}
