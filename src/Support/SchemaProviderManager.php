<?php

namespace NickPotts\Slice\Support;

use NickPotts\Slice\Contracts\SchemaProvider;
use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Exceptions\TableNotFoundException;
use NickPotts\Slice\Support\Cache\SchemaCache;

/**
 * Orchestrates multiple schema providers.
 *
 * Allows any number of providers to be registered. If a table name exists in
 * multiple providers, users must specify the provider explicitly:
 * - 'eloquent:orders.total'
 * - 'clickhouse:events.count'
 *
 * Example:
 * ```php
 * $manager = new SchemaProviderManager();
 * $manager->register(new EloquentSchemaProvider());
 * $manager->register(new ClickHouseProvider());
 *
 * // Unambiguous: only Eloquent has 'customers'
 * $source = $manager->parseMetricSource('customers.total');
 *
 * // Ambiguous: both have 'orders' - must specify
 * $source = $manager->parseMetricSource('eloquent:orders.total');
 * $source = $manager->parseMetricSource('clickhouse:orders.total');
 * ```
 */
class SchemaProviderManager
{
    /** @var array<SchemaProvider> */
    private array $providers = [];

    private SchemaCache $cache;

    public function __construct(?SchemaCache $cache = null)
    {
        $this->cache = $cache ?? new SchemaCache;
    }

    /**
     * Register a schema provider.
     */
    public function register(SchemaProvider $provider): self
    {
        // Boot the provider
        $provider->boot($this->cache);

        $this->providers[$provider->name()] = $provider;

        return $this;
    }

    /**
     * Resolve a table by identifier.
     *
     * If table exists in multiple providers, throws AmbiguousTableException.
     * User must use provider prefix instead: 'eloquent:orders' or 'clickhouse:orders'
     *
     * @param  string  $identifier  Table name or model class
     *
     * @throws TableNotFoundException If table not found in any provider
     * @throws AmbiguousTableException If table exists in multiple providers
     */
    public function resolve(string $identifier): SliceDefinition
    {
        $foundIn = [];
        $firstTable = null;

        foreach ($this->providers as $provider) {
            if ($provider->provides($identifier)) {
                $foundIn[] = $provider->name();
                if ($firstTable === null) {
                    $firstTable = $this->resolveFromProvider($provider, $identifier);
                }
            }
        }

        if (count($foundIn) === 0) {
            throw new TableNotFoundException(
                "Table '{$identifier}' not found in any provider. ".
                'Available providers: '.implode(', ', array_keys($this->providers))
            );
        }

        if (count($foundIn) > 1) {
            throw AmbiguousTableException::forTable($identifier, $foundIn);
        }

        return $firstTable;
    }

    /**
     * Parse and resolve a metric source reference.
     *
     * Supports:
     * - 'orders.total' (unambiguous table.column)
     * - 'eloquent:orders.total' (provider-prefixed table.column)
     * - 'App\Models\Order::total' (model class::column)
     * - 'connection:orders.total' (connection-prefixed, combined with provider or implicit)
     *
     * @param  string  $reference  The metric source reference
     * @return MetricSource Contains resolved table, column, and connection
     *
     * @throws TableNotFoundException If table cannot be resolved
     * @throws AmbiguousTableException If table is ambiguous and no provider prefix given
     * @throws \InvalidArgumentException If reference format is invalid
     */
    public function parseMetricSource(string $reference): MetricSource
    {
        // Parse provider prefix: 'eloquent:orders.total' or 'clickhouse:table.column'
        $providerName = null;
        $connectionName = null;
        $rest = $reference;

        // Check for provider prefix (provider name must match registered provider)
        if (str_contains($rest, ':')) {
            $parts = explode(':', $rest, 2);
            $firstPart = $parts[0];

            // Is this a known provider? If so, it's a provider prefix
            if (isset($this->providers[$firstPart])) {
                $providerName = $firstPart;
                $rest = $parts[1];
            } elseif (! $this->isModelClassName($reference)) {
                // Otherwise, might be a connection prefix
                $connectionName = $firstPart;
                $rest = $parts[1];
            }
        }

        // Parse table.column or Model::column
        if (str_contains($rest, '::')) {
            // Model class reference: App\Models\Order::total
            [$identifier, $column] = explode('::', $rest, 2);
            if ($providerName) {
                $table = $this->resolveFromProvider($this->providers[$providerName], $identifier);
            } else {
                $table = $this->resolve($identifier);
            }
        } elseif (str_contains($rest, '.')) {
            // Table name reference: orders.total
            [$identifier, $column] = explode('.', $rest, 2);
            if ($providerName) {
                $table = $this->resolveFromProvider($this->providers[$providerName], $identifier);
            } else {
                $table = $this->resolve($identifier);
            }
        } else {
            throw new \InvalidArgumentException(
                "Invalid metric source reference: '{$reference}'. ".
                "Expected format: 'table.column' or 'provider:table.column' or 'Model::column'"
            );
        }

        return new MetricSource(
            $table,
            $column,
            $connectionName
        );
    }

    /**
     * Get all tables from all providers.
     *
     * Returns a map of all discovered tables. If a table name exists in multiple
     * providers, all are included with provider prefix keys:
     * - 'orders' (if only one provider has it)
     * - 'eloquent:orders' and 'clickhouse:orders' (if multiple have it)
     *
     * @return array<string, SliceSource>
     */
    public function allTables(): array
    {
        $tablesByName = [];

        // Group tables by name
        foreach ($this->providers as $providerName => $provider) {
            foreach ($provider->tables() as $table) {
                if (! isset($tablesByName[$table->name()])) {
                    $tablesByName[$table->name()] = [];
                }
                $tablesByName[$table->name()][] = [
                    'provider' => $providerName,
                    'table' => $table,
                ];
            }
        }

        // Flatten: if only one provider has table, use bare name. Otherwise use prefixed.
        $result = [];
        foreach ($tablesByName as $name => $entries) {
            if (count($entries) === 1) {
                $result[$name] = $entries[0]['table'];
            } else {
                foreach ($entries as $entry) {
                    $result["{$entry['provider']}:{$name}"] = $entry['table'];
                }
            }
        }

        return $result;
    }

    /**
     * Get a specific registered provider by name.
     *
     * @param  string  $name  Provider name (from SchemaProvider::name())
     */
    public function getProvider(string $name): ?SchemaProvider
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, SchemaProvider>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get the schema cache instance.
     */
    public function getCache(): SchemaCache
    {
        return $this->cache;
    }

    /**
     * Check if a table identifier can be resolved without ambiguity.
     */
    public function canResolve(string $identifier): bool
    {
        try {
            $this->resolve($identifier);

            return true;
        } catch (TableNotFoundException|AmbiguousTableException) {
            return false;
        }
    }

    /**
     * Get available table identifiers (including provider-prefixed for ambiguous ones).
     *
     * @return array<string>
     */
    public function getAvailableTables(): array
    {
        return array_keys($this->allTables());
    }

    /**
     * Helper to resolve a table from a specific provider.
     */
    private function resolveFromProvider(SchemaProvider $provider, string $identifier): SliceDefinition
    {
        try {
            $source = $provider->resolveMetricSource($identifier.'.id');

            return $source->slice;
        } catch (\Throwable $e) {
            throw new TableNotFoundException(
                "Provider '{$provider->name()}' could not resolve table '{$identifier}': ".$e->getMessage()
            );
        }
    }

    /**
     * Check if a string looks like a model class name (contains backslash).
     */
    private function isModelClassName(string $reference): bool
    {
        return str_contains($reference, '\\');
    }
}
