<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * FeedbackSubmission Model
 *
 * Manages customer feedback, reviews, and ratings for events.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property int $event_id
 * @property int|null $booking_id
 * @property string $feedback_type
 * @property int|null $overall_rating
 * @property int|null $venue_rating
 * @property int|null $organization_rating
 * @property int|null $value_rating
 * @property string|null $title
 * @property string|null $comment
 * @property array|null $media_attachments
 * @property bool $is_anonymous
 * @property bool $allow_public_display
 * @property string $status
 * @property string|null $moderated_by
 * @property \Carbon\Carbon|null $moderated_at
 * @property string|null $moderation_notes
 * @property bool $is_featured
 * @property int $helpful_votes
 * @property int $total_votes
 * @property array|null $sentiment_analysis
 * @property array|null $metadata
 */
class FeedbackSubmission extends Model
{
    use HasTenantScope, SoftDeletes;

    protected $table = 'feedback_submissions';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'event_id',
        'booking_id',
        'feedback_type',
        'overall_rating',
        'venue_rating',
        'organization_rating',
        'value_rating',
        'title',
        'comment',
        'media_attachments',
        'is_anonymous',
        'allow_public_display',
        'status',
        'moderated_by',
        'moderated_at',
        'moderation_notes',
        'is_featured',
        'helpful_votes',
        'total_votes',
        'sentiment_analysis',
        'metadata',
    ];

    protected $casts = [
        'overall_rating' => 'integer',
        'venue_rating' => 'integer',
        'organization_rating' => 'integer',
        'value_rating' => 'integer',
        'media_attachments' => 'array',
        'is_anonymous' => 'boolean',
        'allow_public_display' => 'boolean',
        'moderated_at' => 'datetime',
        'is_featured' => 'boolean',
        'helpful_votes' => 'integer',
        'total_votes' => 'integer',
        'sentiment_analysis' => 'array',
        'metadata' => 'array',
    ];

    protected $dates = [
        'moderated_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the event associated with this feedback
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the booking associated with this feedback
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Scope for approved feedback
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope for public display
     */
    public function scopePublic($query)
    {
        return $query->where('allow_public_display', true)
            ->where('status', 'approved');
    }

    /**
     * Scope for pending moderation
     */
    public function scopePendingModeration($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for specific feedback type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('feedback_type', $type);
    }

    /**
     * Scope for specific event
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for featured feedback
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope for feedback with ratings
     */
    public function scopeWithRatings($query)
    {
        return $query->whereNotNull('overall_rating');
    }

    /**
     * Scope for high-rated feedback
     */
    public function scopeHighRated($query, int $minRating = 4)
    {
        return $query->where('overall_rating', '>=', $minRating);
    }

    /**
     * Approve feedback
     */
    public function approve(string $moderatorId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => 'approved',
            'moderated_by' => $moderatorId,
            'moderated_at' => now(),
            'moderation_notes' => $notes,
        ]);
    }

    /**
     * Reject feedback
     */
    public function reject(string $moderatorId, string $reason): bool
    {
        return $this->update([
            'status' => 'rejected',
            'moderated_by' => $moderatorId,
            'moderated_at' => now(),
            'moderation_notes' => $reason,
        ]);
    }

    /**
     * Flag feedback for review
     */
    public function flag(string $moderatorId, string $reason): bool
    {
        return $this->update([
            'status' => 'flagged',
            'moderated_by' => $moderatorId,
            'moderated_at' => now(),
            'moderation_notes' => $reason,
        ]);
    }

    /**
     * Feature feedback
     */
    public function feature(): bool
    {
        return $this->update(['is_featured' => true]);
    }

    /**
     * Unfeature feedback
     */
    public function unfeature(): bool
    {
        return $this->update(['is_featured' => false]);
    }

    /**
     * Add helpful vote
     */
    public function addHelpfulVote(): bool
    {
        return $this->increment('helpful_votes') && $this->increment('total_votes');
    }

    /**
     * Add unhelpful vote
     */
    public function addUnhelpfulVote(): bool
    {
        return $this->increment('total_votes');
    }

    /**
     * Get helpfulness percentage
     */
    public function getHelpfulnessPercentage(): float
    {
        if ($this->total_votes === 0) {
            return 0.0;
        }

        return round(($this->helpful_votes / $this->total_votes) * 100, 1);
    }

    /**
     * Get average rating for an event
     */
    public static function getAverageRatingForEvent(int $eventId, string $tenantId): ?float
    {
        $average = self::forTenant($tenantId)
            ->forEvent($eventId)
            ->approved()
            ->whereNotNull('overall_rating')
            ->avg('overall_rating');

        return $average ? round($average, 1) : null;
    }

    /**
     * Get rating distribution for an event
     */
    public static function getRatingDistributionForEvent(int $eventId, string $tenantId): array
    {
        $distribution = self::forTenant($tenantId)
            ->forEvent($eventId)
            ->approved()
            ->whereNotNull('overall_rating')
            ->selectRaw('overall_rating, COUNT(*) as count')
            ->groupBy('overall_rating')
            ->orderBy('overall_rating', 'desc')
            ->pluck('count', 'overall_rating')
            ->toArray();

        // Fill in missing ratings with 0
        for ($i = 1; $i <= 5; $i++) {
            if (! isset($distribution[$i])) {
                $distribution[$i] = 0;
            }
        }

        ksort($distribution);

        return $distribution;
    }

    /**
     * Get feedback types
     */
    public static function getFeedbackTypes(): array
    {
        return [
            'rating' => 'Rating Only',
            'review' => 'Review with Rating',
            'complaint' => 'Complaint',
            'suggestion' => 'Suggestion',
            'testimonial' => 'Testimonial',
        ];
    }

    /**
     * Get status options
     */
    public static function getStatusOptions(): array
    {
        return [
            'pending' => 'Pending Moderation',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'flagged' => 'Flagged for Review',
        ];
    }
}
