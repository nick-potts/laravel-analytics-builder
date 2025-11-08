<?php

namespace NickPotts\Slice\Support\Cache;

/**
 * Caching layer for schema provider metadata.
 *
 * Allows providers to cache their introspection results to improve startup time.
 * Different providers can implement different cache strategies:
 * - EloquentSchemaProvider: File mtime-based invalidation
 * - ClickHouseProvider: Time-based TTL (24h)
 * - ManualTableProvider: No caching needed
 */
class SchemaCache
{
    /** @var array<string, array> */
    private array $cache = [];

    private bool $enabled = true;

    /**
     * Store data in cache.
     *
     * @param  string  $key  Cache key
     * @param  array  $value  Data to cache
     */
    public function put(string $key, array $value): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache[$key] = $value;
    }

    /**
     * Retrieve data from cache.
     */
    public function get(string $key): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        return $this->cache[$key] ?? null;
    }

    /**
     * Check if key exists in cache.
     */
    public function has(string $key): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return isset($this->cache[$key]);
    }

    /**
     * Clear a specific cache entry.
     */
    public function forget(string $key): void
    {
        unset($this->cache[$key]);
    }

    /**
     * Clear all cache.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Disable caching (useful for development).
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Enable caching.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Check if caching is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all cached data (for debugging).
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->cache;
    }

    /**
     * Get cache size (for monitoring).
     */
    public function size(): int
    {
        return count($this->cache);
    }
}
