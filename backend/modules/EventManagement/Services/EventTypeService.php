<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\EventType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EventTypeService
{
    /**
     * Cache key for all active event types
     */
    private const CACHE_KEY_ACTIVE_TYPES = 'event_types:active';

    /**
     * Cache key prefix for event type by slug
     */
    private const CACHE_KEY_BY_SLUG_PREFIX = 'event_types:slug:';

    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Get all active event types
     * Results are cached to reduce database queries
     *
     * @return Collection<EventType>
     */
    public function getAllActiveTypes(): Collection
    {
        return Cache::remember(
            self::CACHE_KEY_ACTIVE_TYPES,
            self::CACHE_TTL,
            fn() => EventType::active()->orderBy('name')->get()
        );
    }

    /**
     * Get event type by slug
     * Results are cached to reduce database queries
     *
     * @param string $slug
     * @return EventType|null
     */
    public function getTypeBySlug(string $slug): ?EventType
    {
        $cacheKey = self::CACHE_KEY_BY_SLUG_PREFIX . $slug;

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => EventType::where('slug', $slug)->first()
        );
    }

    /**
     * Create a new event type
     * Validates uniqueness of name and slug
     *
     * @param array $data
     * @return EventType
     * @throws ValidationException
     */
    public function createType(array $data): EventType
    {
        // Validate unique name
        if (EventType::where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['An event type with this name already exists.']
            ]);
        }

        // Validate unique slug
        if (EventType::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['An event type with this slug already exists.']
            ]);
        }

        $eventType = EventType::create($data);

        // Clear cache after creating
        $this->clearCache();

        return $eventType;
    }

    /**
     * Update an existing event type
     * Validates uniqueness of name and slug (excluding current record)
     *
     * @param string $id
     * @param array $data
     * @return EventType
     * @throws ValidationException
     */
    public function updateType(string $id, array $data): EventType
    {
        $eventType = EventType::findOrFail($id);

        // Validate unique name (excluding current record)
        if (isset($data['name']) && 
            EventType::where('name', $data['name'])
                ->where('id', '!=', $id)
                ->exists()) {
            throw ValidationException::withMessages([
                'name' => ['An event type with this name already exists.']
            ]);
        }

        // Validate unique slug (excluding current record)
        if (isset($data['slug']) && 
            EventType::where('slug', $data['slug'])
                ->where('id', '!=', $id)
                ->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['An event type with this slug already exists.']
            ]);
        }

        $eventType->update($data);

        // Clear cache after updating
        $this->clearCache();

        return $eventType->fresh();
    }

    /**
     * Clear all event type caches
     *
     * @return void
     */
    private function clearCache(): void
    {
        // Clear active types cache
        Cache::forget(self::CACHE_KEY_ACTIVE_TYPES);

        // Clear all slug-based caches
        // Note: In production, consider using cache tags for more efficient clearing
        $eventTypes = EventType::all();
        foreach ($eventTypes as $eventType) {
            Cache::forget(self::CACHE_KEY_BY_SLUG_PREFIX . $eventType->slug);
        }
    }
}
