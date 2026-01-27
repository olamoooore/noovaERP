<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * CustomerJourneyEvent Model
 * 
 * Tracks comprehensive customer behavior and journey analytics.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string|null $session_id
 * @property string $event_type
 * @property string|null $page_url
 * @property string|null $referrer_url
 * @property int|null $related_event_id
 * @property int|null $related_booking_id
 * @property array|null $event_properties
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $os
 * @property mixed|null $location
 * @property \Carbon\Carbon $event_timestamp
 * @property array|null $metadata
 */
class CustomerJourneyEvent extends Model
{
    use HasTenantScope;

    protected $table = 'customer_journey_events';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'session_id',
        'event_type',
        'page_url',
        'referrer_url',
        'related_event_id',
        'related_booking_id',
        'event_properties',
        'user_agent',
        'ip_address',
        'device_type',
        'browser',
        'os',
        'location',
        'event_timestamp',
        'metadata',
    ];

    protected $casts = [
        'event_properties' => 'array',
        'event_timestamp' => 'datetime',
        'metadata' => 'array',
    ];

    protected $dates = [
        'event_timestamp',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the related event
     */
    public function relatedEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'related_event_id');
    }

    /**
     * Get the related booking
     */
    public function relatedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'related_booking_id');
    }

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for specific session
     */
    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope for specific event type
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope for specific device type
     */
    public function scopeByDeviceType($query, string $deviceType)
    {
        return $query->where('device_type', $deviceType);
    }

    /**
     * Scope for date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('event_timestamp', [$startDate, $endDate]);
    }

    /**
     * Scope for recent events
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('event_timestamp', '>=', now()->subDays($days));
    }

    /**
     * Track a customer journey event
     */
    public static function trackEvent(
        string $tenantId,
        string $customerId,
        string $eventType,
        ?string $sessionId = null,
        ?array $eventProperties = null,
        ?int $relatedEventId = null,
        ?int $relatedBookingId = null,
        ?array $metadata = null
    ): self {
        $request = request();
        
        return self::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'session_id' => $sessionId ?? session()->getId(),
            'event_type' => $eventType,
            'page_url' => $request->fullUrl(),
            'referrer_url' => $request->header('referer'),
            'related_event_id' => $relatedEventId,
            'related_booking_id' => $relatedBookingId,
            'event_properties' => $eventProperties,
            'user_agent' => $request->header('User-Agent'),
            'ip_address' => $request->ip(),
            'device_type' => self::detectDeviceType($request->header('User-Agent')),
            'browser' => self::detectBrowser($request->header('User-Agent')),
            'os' => self::detectOS($request->header('User-Agent')),
            'event_timestamp' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get customer journey for a specific customer
     */
    public static function getCustomerJourney(
        string $tenantId,
        string $customerId,
        int $days = 30
    ): \Illuminate\Database\Eloquent\Collection {
        return self::forTenant($tenantId)
            ->forCustomer($customerId)
            ->recent($days)
            ->with(['relatedEvent', 'relatedBooking'])
            ->orderBy('event_timestamp', 'desc')
            ->get();
    }

    /**
     * Get session journey
     */
    public static function getSessionJourney(
        string $tenantId,
        string $sessionId
    ): \Illuminate\Database\Eloquent\Collection {
        return self::forTenant($tenantId)
            ->forSession($sessionId)
            ->with(['relatedEvent', 'relatedBooking'])
            ->orderBy('event_timestamp', 'asc')
            ->get();
    }

    /**
     * Get funnel analysis
     */
    public static function getFunnelAnalysis(
        string $tenantId,
        array $eventTypes,
        int $days = 30
    ): array {
        $results = [];
        
        foreach ($eventTypes as $eventType) {
            $count = self::forTenant($tenantId)
                ->byEventType($eventType)
                ->recent($days)
                ->distinct('customer_id')
                ->count('customer_id');
                
            $results[$eventType] = $count;
        }
        
        return $results;
    }

    /**
     * Get conversion funnel
     */
    public static function getConversionFunnel(
        string $tenantId,
        int $days = 30
    ): array {
        $funnelSteps = [
            'page_view' => 'Page Views',
            'event_view' => 'Event Views',
            'booking_started' => 'Booking Started',
            'booking_completed' => 'Booking Completed',
        ];
        
        return self::getFunnelAnalysis($tenantId, array_keys($funnelSteps), $days);
    }

    /**
     * Get device analytics
     */
    public static function getDeviceAnalytics(
        string $tenantId,
        int $days = 30
    ): array {
        return self::forTenant($tenantId)
            ->recent($days)
            ->selectRaw('device_type, COUNT(*) as count')
            ->whereNotNull('device_type')
            ->groupBy('device_type')
            ->orderBy('count', 'desc')
            ->pluck('count', 'device_type')
            ->toArray();
    }

    /**
     * Get popular pages
     */
    public static function getPopularPages(
        string $tenantId,
        int $days = 30,
        int $limit = 10
    ): array {
        return self::forTenant($tenantId)
            ->recent($days)
            ->byEventType('page_view')
            ->selectRaw('page_url, COUNT(*) as views')
            ->whereNotNull('page_url')
            ->groupBy('page_url')
            ->orderBy('views', 'desc')
            ->limit($limit)
            ->pluck('views', 'page_url')
            ->toArray();
    }

    /**
     * Detect device type from user agent
     */
    private static function detectDeviceType(?string $userAgent): ?string
    {
        if (!$userAgent) return null;
        
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false || strpos($userAgent, 'iphone') !== false) {
            return 'mobile';
        } elseif (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'tablet';
        } else {
            return 'desktop';
        }
    }

    /**
     * Detect browser from user agent
     */
    private static function detectBrowser(?string $userAgent): ?string
    {
        if (!$userAgent) return null;
        
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'safari') !== false) return 'Safari';
        if (strpos($userAgent, 'edge') !== false) return 'Edge';
        if (strpos($userAgent, 'opera') !== false) return 'Opera';
        
        return 'Other';
    }

    /**
     * Detect OS from user agent
     */
    private static function detectOS(?string $userAgent): ?string
    {
        if (!$userAgent) return null;
        
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'windows') !== false) return 'Windows';
        if (strpos($userAgent, 'mac') !== false) return 'macOS';
        if (strpos($userAgent, 'linux') !== false) return 'Linux';
        if (strpos($userAgent, 'android') !== false) return 'Android';
        if (strpos($userAgent, 'ios') !== false || strpos($userAgent, 'iphone') !== false || strpos($userAgent, 'ipad') !== false) return 'iOS';
        
        return 'Other';
    }

    /**
     * Get event types
     */
    public static function getEventTypes(): array
    {
        return [
            'page_view' => 'Page View',
            'event_view' => 'Event View',
            'search' => 'Search',
            'filter_applied' => 'Filter Applied',
            'bookmark_added' => 'Bookmark Added',
            'booking_started' => 'Booking Started',
            'booking_abandoned' => 'Booking Abandoned',
            'booking_completed' => 'Booking Completed',
            'ticket_downloaded' => 'Ticket Downloaded',
            'check_in_completed' => 'Check-in Completed',
            'feedback_submitted' => 'Feedback Submitted',
            'recommendation_clicked' => 'Recommendation Clicked',
            'share_action' => 'Share Action',
            'support_contact' => 'Support Contact',
        ];
    }
}