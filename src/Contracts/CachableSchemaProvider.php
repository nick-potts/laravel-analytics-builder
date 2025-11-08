<?php

namespace NickPotts\Slice\Contracts;

/**
 * Interface for schema providers that support caching.
 *
 * Providers implementing this interface can serialize/deserialize their
 * metadata to/from cache, reducing startup time and introspection overhead.
 *
 * Different providers have different cache invalidation strategies:
 * - EloquentSchemaProvider: File mtime-based (detects model changes)
 * - ClickHouseProvider: Time-based (24h TTL)
 * - ManualTableProvider: No cache needed (static)
 */
interface CachableSchemaProvider extends SchemaProvider
{
    /**
     * Generate a unique cache key for this provider's metadata.
     *
     * Should be deterministic based on provider configuration.
     * Example: 'eloquent_schema_' . md5(serialize($namespaces))
     */
    public function cacheKey(): string;

    /**
     * Serialize provider metadata to cache-friendly format.
     *
     * @return array Cacheable array representation of all provider metadata
     */
    public function toCache(): array;

    /**
     * Restore provider from cached metadata.
     *
     * @param  array  $cached  Previously cached data from toCache()
     */
    public function fromCache(array $cached): void;

    /**
     * Check if the current cache is still valid.
     *
     * Different strategies per provider:
     * - Eloquent: Check if model files have changed (file mtime)
     * - ClickHouse: Check if 24h TTL has expired
     * - APIs: Check if credentials/config have changed
     *
     * @return bool True if cache is still valid, false to re-introspect
     */
    public function isCacheValid(): bool;
}
