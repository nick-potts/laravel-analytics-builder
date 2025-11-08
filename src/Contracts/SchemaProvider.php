<?php

namespace NickPotts\Slice\Contracts;

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Support\Cache\SchemaCache;
use NickPotts\Slice\Support\MetricSource;

/**
 * Contract for schema providers.
 *
 * A schema provider is responsible for discovering and providing table metadata
 * from any data source (Eloquent models, manual definitions, APIs, etc.).
 *
 * Multiple providers can be registered, but table names must be unique across
 * all providers. If a duplicate table name is detected, an exception is thrown
 * at registration time to fail loudly.
 *
 * To use a specific provider for ambiguous table names, use explicit provider
 * prefixes like 'eloquent:orders.total' or 'manual:customers.id'.
 */
interface SchemaProvider
{
    /**
     * Boot the provider with caching support.
     *
     * Called when the provider is registered. Providers that implement
     * CachableSchemaProvider should load from cache if valid.
     */
    public function boot(SchemaCache $cache): void;

    /**
     * Get all tables this provider can supply.
     *
     * @return iterable<TableContract>
     */
    public function tables(): iterable;

    /**
     * Check if this provider can supply a specific table.
     *
     * @param  string  $identifier  Table name, model class, or custom identifier
     */
    public function provides(string $identifier): bool;

    /**
     * Resolve a metric source reference to table + column metadata.
     *
     * Supports:
     * - 'orders.total' (table.column notation)
     * - 'connection:orders.total' (with connection prefix)
     * - 'App\Models\Order::total' (model class notation)
     *
     * @param  string  $reference  The metric source reference
     * @return MetricSource Contains table contract, column name, and connection
     *
     * @throws \InvalidArgumentException If reference cannot be resolved
     */
    public function resolveMetricSource(string $reference): MetricSource;

    /**
     * Get relation graph for a table.
     */
    public function relations(string $table): RelationGraph;

    /**
     * Get dimension catalog for a table.
     */
    public function dimensions(string $table): DimensionCatalog;

    /**
     * Get provider name/identifier for error messages and metric prefixes.
     *
     * Used in metric sources like 'eloquent:orders.total' or 'clickhouse:events.count'.
     * Must be unique across all registered providers.
     */
    public function name(): string;
}
