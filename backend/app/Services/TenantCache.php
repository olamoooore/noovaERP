<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TenantCache
{
    /**
     * Store an item in the cache for the current tenant.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public static function put(string $key, $value, $ttl = null): bool
    {
        return Cache::put(static::scopedKey($key), $value, $ttl);
    }

    /**
     * Retrieve an item from the cache for the current tenant.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return Cache::get(static::scopedKey($key), $default);
    }

    /**
     * Store an item in the cache for the current tenant if the key does not exist.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public static function add(string $key, $value, $ttl = null): bool
    {
        return Cache::add(static::scopedKey($key), $value, $ttl);
    }

    /**
     * Remove an item from the cache for the current tenant.
     *
     * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        return Cache::forget(static::scopedKey($key));
    }

    /**
     * Get an item from the cache for the current tenant, or execute the given Closure and store the result.
     *
     * @param string $key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public static function remember(string $key, $ttl, \Closure $callback)
    {
        return Cache::remember(static::scopedKey($key), $ttl, $callback);
    }

    /**
     * Generate a tenant-scoped cache key.
     *
     * @param string $key
     * @return string
     */
    protected static function scopedKey(string $key): string
    {
        $tenantId = tenancy()->tenant?->id ?? 'global';
        return "tenant:{$tenantId}:{$key}";
    }
}
