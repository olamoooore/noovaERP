<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * EventRecommendation Model
 *
 * Manages personalized event recommendations for customers.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property int $event_id
 * @property string $recommendation_type
 * @property float $confidence_score
 * @property array|null $recommendation_factors
 * @property string $status
 * @property \Carbon\Carbon $generated_at
 * @property \Carbon\Carbon|null $shown_at
 * @property \Carbon\Carbon|null $interacted_at
 * @property string|null $interaction_type
 * @property array|null $metadata
 */
class EventRecommendation extends Model
{
    use HasTenantScope;

    protected $table = 'event_recommendations';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'event_id',
        'recommendation_type',
        'confidence_score',
        'recommendation_factors',
        'status',
        'generated_at',
        'shown_at',
        'interacted_at',
        'interaction_type',
        'metadata',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'recommendation_factors' => 'array',
        'generated_at' => 'datetime',
        'shown_at' => 'datetime',
        'interacted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $dates = [
        'generated_at',
        'shown_at',
        'interacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the event associated with this recommendation
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for specific recommendation type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('recommendation_type', $type);
    }

    /**
     * Scope for high confidence recommendations
     */
    public function scopeHighConfidence($query, float $minScore = 0.7)
    {
        return $query->where('confidence_score', '>=', $minScore);
    }

    /**
     * Scope for unshown recommendations
     */
    public function scopeUnshown($query)
    {
        return $query->where('status', 'generated');
    }

    /**
     * Scope for shown recommendations
     */
    public function scopeShown($query)
    {
        return $query->whereIn('status', ['shown', 'clicked', 'booked', 'dismissed']);
    }

    /**
     * Scope for interacted recommendations
     */
    public function scopeInteracted($query)
    {
        return $query->whereIn('status', ['clicked', 'booked', 'dismissed']);
    }

    /**
     * Scope for recent recommendations
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('generated_at', '>=', now()->subDays($days));
    }

    /**
     * Mark recommendation as shown
     */
    public function markAsShown(): bool
    {
        return $this->update([
            'status' => 'shown',
            'shown_at' => now(),
        ]);
    }

    /**
     * Mark recommendation as clicked
     */
    public function markAsClicked(): bool
    {
        return $this->update([
            'status' => 'clicked',
            'interacted_at' => now(),
            'interaction_type' => 'clicked',
        ]);
    }

    /**
     * Mark recommendation as booked
     */
    public function markAsBooked(): bool
    {
        return $this->update([
            'status' => 'booked',
            'interacted_at' => now(),
            'interaction_type' => 'booked',
        ]);
    }

    /**
     * Mark recommendation as dismissed
     */
    public function markAsDismissed(): bool
    {
        return $this->update([
            'status' => 'dismissed',
            'interacted_at' => now(),
            'interaction_type' => 'dismissed',
        ]);
    }

    /**
     * Get recommendations for customer
     */
    public static function getRecommendationsForCustomer(
        string $tenantId,
        string $customerId,
        int $limit = 10,
        ?string $type = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = self::forTenant($tenantId)
            ->forCustomer($customerId)
            ->with('event')
            ->orderBy('confidence_score', 'desc')
            ->orderBy('generated_at', 'desc')
            ->limit($limit);

        if ($type) {
            $query->byType($type);
        }

        return $query->get();
    }

    /**
     * Generate recommendation
     */
    public static function generateRecommendation(
        string $tenantId,
        string $customerId,
        int $eventId,
        string $recommendationType,
        float $confidenceScore,
        array $factors,
        ?array $metadata = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'event_id' => $eventId,
            'recommendation_type' => $recommendationType,
            'confidence_score' => $confidenceScore,
            'recommendation_factors' => $factors,
            'status' => 'generated',
            'generated_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get recommendation types
     */
    public static function getRecommendationTypes(): array
    {
        return [
            'similar_events' => 'Similar Events',
            'artist_based' => 'Based on Favorite Artists',
            'location_based' => 'Based on Location',
            'price_based' => 'Based on Price Preferences',
            'collaborative_filtering' => 'People Like You Also Liked',
            'trending' => 'Trending Events',
            'seasonal' => 'Seasonal Recommendations',
            'personalized' => 'Personalized for You',
        ];
    }

    /**
     * Calculate click-through rate for recommendations
     */
    public static function getClickThroughRate(
        string $tenantId,
        ?string $customerId = null,
        ?string $recommendationType = null,
        int $days = 30
    ): float {
        $query = self::forTenant($tenantId)
            ->recent($days);

        if ($customerId) {
            $query->forCustomer($customerId);
        }

        if ($recommendationType) {
            $query->byType($recommendationType);
        }

        $total = $query->shown()->count();
        $clicked = $query->interacted()->count();

        return $total > 0 ? round(($clicked / $total) * 100, 2) : 0.0;
    }

    /**
     * Get recommendation performance metrics
     */
    public static function getPerformanceMetrics(
        string $tenantId,
        int $days = 30
    ): array {
        $baseQuery = self::forTenant($tenantId)->recent($days);

        return [
            'total_generated' => (clone $baseQuery)->count(),
            'total_shown' => (clone $baseQuery)->shown()->count(),
            'total_clicked' => (clone $baseQuery)->where('status', 'clicked')->count(),
            'total_booked' => (clone $baseQuery)->where('status', 'booked')->count(),
            'total_dismissed' => (clone $baseQuery)->where('status', 'dismissed')->count(),
            'click_through_rate' => self::getClickThroughRate($tenantId, null, null, $days),
            'conversion_rate' => self::getConversionRate($tenantId, $days),
            'average_confidence' => (clone $baseQuery)->avg('confidence_score'),
        ];
    }

    /**
     * Calculate conversion rate (bookings from recommendations)
     */
    public static function getConversionRate(string $tenantId, int $days = 30): float
    {
        $shown = self::forTenant($tenantId)->recent($days)->shown()->count();
        $booked = self::forTenant($tenantId)->recent($days)->where('status', 'booked')->count();

        return $shown > 0 ? round(($booked / $shown) * 100, 2) : 0.0;
    }
}
