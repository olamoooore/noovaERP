<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * CustomerPreference Model
 *
 * Stores customer personalization settings and preferences for event recommendations.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property array|null $preferred_genres
 * @property array|null $preferred_artists
 * @property array|null $preferred_venues
 * @property array|null $preferred_price_range
 * @property array|null $preferred_event_times
 * @property int|null $travel_distance_km
 * @property array|null $accessibility_needs
 * @property array|null $dietary_restrictions
 * @property string $communication_style
 * @property string $preferred_language
 * @property array|null $custom_preferences
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class CustomerPreference extends Model
{
    use HasTenantScope;

    protected $table = 'customer_preferences';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'preferred_genres',
        'preferred_artists',
        'preferred_venues',
        'preferred_price_range',
        'preferred_event_times',
        'travel_distance_km',
        'accessibility_needs',
        'dietary_restrictions',
        'communication_style',
        'preferred_language',
        'custom_preferences',
    ];

    protected $casts = [
        'preferred_genres' => 'array',
        'preferred_artists' => 'array',
        'preferred_venues' => 'array',
        'preferred_price_range' => 'array',
        'preferred_event_times' => 'array',
        'accessibility_needs' => 'array',
        'dietary_restrictions' => 'array',
        'custom_preferences' => 'array',
    ];

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Get or create preferences for a customer
     */
    public static function getOrCreateForCustomer(string $tenantId, string $customerId): self
    {
        return self::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
            ],
            [
                'communication_style' => 'standard',
                'preferred_language' => 'en',
            ]
        );
    }

    /**
     * Update customer preferences
     */
    public function updatePreferences(array $preferences): bool
    {
        return $this->update($preferences);
    }

    /**
     * Get available communication styles
     */
    public static function getCommunicationStyles(): array
    {
        return [
            'minimal' => 'Minimal - Only essential notifications',
            'standard' => 'Standard - Regular updates and reminders',
            'detailed' => 'Detailed - All available information and updates',
        ];
    }

    /**
     * Get supported languages
     */
    public static function getSupportedLanguages(): array
    {
        return [
            'en' => 'English',
            'ko' => 'Korean',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'es' => 'Spanish',
            'fr' => 'French',
        ];
    }

    /**
     * Check if customer prefers a specific genre
     */
    public function prefersGenre(string $genre): bool
    {
        return in_array($genre, $this->preferred_genres ?? []);
    }

    /**
     * Check if customer prefers a specific artist
     */
    public function prefersArtist(int $artistId): bool
    {
        return in_array($artistId, $this->preferred_artists ?? []);
    }

    /**
     * Check if price is within customer's preferred range
     */
    public function isPriceInRange(float $price): bool
    {
        if (! $this->preferred_price_range) {
            return true; // No preference set
        }

        $min = $this->preferred_price_range['min'] ?? 0;
        $max = $this->preferred_price_range['max'] ?? PHP_FLOAT_MAX;

        return $price >= $min && $price <= $max;
    }

    /**
     * Get accessibility needs as formatted array
     */
    public function getAccessibilityNeeds(): array
    {
        return $this->accessibility_needs ?? [];
    }

    /**
     * Get dietary restrictions as formatted array
     */
    public function getDietaryRestrictions(): array
    {
        return $this->dietary_restrictions ?? [];
    }

    /**
     * Add a preferred genre
     */
    public function addPreferredGenre(string $genre): void
    {
        $genres = $this->preferred_genres ?? [];
        if (! in_array($genre, $genres)) {
            $genres[] = $genre;
            $this->preferred_genres = $genres;
            $this->save();
        }
    }

    /**
     * Remove a preferred genre
     */
    public function removePreferredGenre(string $genre): void
    {
        $genres = $this->preferred_genres ?? [];
        $genres = array_filter($genres, fn ($g) => $g !== $genre);
        $this->preferred_genres = array_values($genres);
        $this->save();
    }

    /**
     * Add a preferred artist
     */
    public function addPreferredArtist(int $artistId): void
    {
        $artists = $this->preferred_artists ?? [];
        if (! in_array($artistId, $artists)) {
            $artists[] = $artistId;
            $this->preferred_artists = $artists;
            $this->save();
        }
    }

    /**
     * Remove a preferred artist
     */
    public function removePreferredArtist(int $artistId): void
    {
        $artists = $this->preferred_artists ?? [];
        $artists = array_filter($artists, fn ($a) => $a !== $artistId);
        $this->preferred_artists = array_values($artists);
        $this->save();
    }

    /**
     * Set price range preference
     */
    public function setPriceRange(?float $min = null, ?float $max = null): void
    {
        $range = [];
        if ($min !== null) {
            $range['min'] = $min;
        }
        if ($max !== null) {
            $range['max'] = $max;
        }

        $this->preferred_price_range = ! empty($range) ? $range : null;
        $this->save();
    }
}
