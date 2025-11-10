<?php

namespace NickPotts\Slice\Support;

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * Pre-compiled schema containing all tables, relations, and dimensions.
 *
 * Built once at application bootstrap and stored as a singleton.
 * Provides O(1) lookups for all schema metadata, eliminating per-query
 * redundant schema resolution.
 *
 * Immutable after construction.
 */
final class CompiledSchema
{
    /**
     * All discovered tables indexed by identifier.
     * Example keys: 'eloquent:orders', 'manual:customers'
     *
     * @var array<string, SliceDefinition>
     */
    private readonly array $tablesByIdentifier;

    /**
     * Tables indexed by bare name (without provider prefix).
     * Used for fast lookup when only table name is known.
     * Example keys: 'orders', 'customers'
     *
     * @var array<string, SliceDefinition>
     */
    private readonly array $tablesByName;

    /**
     * Index mapping table identifier to provider name.
     * Example: 'eloquent:orders' => 'eloquent'
     *
     * @var array<string, string>
     */
    private readonly array $tableProviders;

    /**
     * Pre-computed relation graphs for all tables.
     * Eliminates runtime relation fetching during join resolution.
     * Example keys: 'eloquent:orders', 'eloquent:customers'
     *
     * @var array<string, RelationGraph>
     */
    private readonly array $relations;

    /**
     * Pre-computed dimension catalogs for all tables.
     * Eliminates runtime dimension fetching.
     * Example keys: 'eloquent:orders', 'eloquent:customers'
     *
     * @var array<string, DimensionCatalog>
     */
    private readonly array $dimensions;

    /**
     * Connection index mapping provider:connection to tables on that connection.
     * Used for connection validation and queries.
     * Example: 'eloquent:mysql' => ['eloquent:orders', 'eloquent:customers']
     *          'eloquent:null' => ['eloquent:users'] (using default connection)
     *
     * @var array<string, array<string>>
     */
    private readonly array $connectionIndex;

    /**
     * Create a new compiled schema.
     *
     * @param  array<string, SliceDefinition>  $tablesByIdentifier
     * @param  array<string, SliceDefinition>  $tablesByName
     * @param  array<string, string>  $tableProviders
     * @param  array<string, RelationGraph>  $relations
     * @param  array<string, DimensionCatalog>  $dimensions
     * @param  array<string, array<string>>  $connectionIndex
     */
    public function __construct(
        array $tablesByIdentifier,
        array $tablesByName,
        array $tableProviders,
        array $relations,
        array $dimensions,
        array $connectionIndex,
    ) {
        $this->tablesByIdentifier = $tablesByIdentifier;
        $this->tablesByName = $tablesByName;
        $this->tableProviders = $tableProviders;
        $this->relations = $relations;
        $this->dimensions = $dimensions;
        $this->connectionIndex = $connectionIndex;
    }

    /**
     * Resolve a table by identifier.
     *
     * Identifier can be:
     * - Full: 'eloquent:orders' or 'manual:customers'
     * - Bare: 'orders' or 'customers' (searches all tables)
     *
     * @return SliceDefinition|null The table definition or null if not found
     */
    public function resolveTable(string $identifier): ?SliceDefinition
    {
        // Try exact match first (full identifier)
        if (isset($this->tablesByIdentifier[$identifier])) {
            return $this->tablesByIdentifier[$identifier];
        }

        // Try by bare name
        if (isset($this->tablesByName[$identifier])) {
            return $this->tablesByName[$identifier];
        }

        return null;
    }

    /**
     * Resolve a table by bare name only.
     *
     * Only searches by table name, not full identifier.
     * Returns null if not found or ambiguous.
     *
     * @return SliceDefinition|null The table definition or null
     */
    public function resolveTableByName(string $name): ?SliceDefinition
    {
        return $this->tablesByName[$name] ?? null;
    }

    /**
     * Get relation graph for a table.
     *
     * Throws if table not found in compiled schema.
     *
     * @throws \RuntimeException If table not found
     */
    public function getRelations(string $tableIdentifier): RelationGraph
    {
        if (! isset($this->relations[$tableIdentifier])) {
            throw new \RuntimeException(
                "No relations found for table '{$tableIdentifier}' in compiled schema."
            );
        }

        return $this->relations[$tableIdentifier];
    }

    /**
     * Get dimension catalog for a table.
     *
     * Throws if table not found in compiled schema.
     *
     * @throws \RuntimeException If table not found
     */
    public function getDimensions(string $tableIdentifier): DimensionCatalog
    {
        if (! isset($this->dimensions[$tableIdentifier])) {
            throw new \RuntimeException(
                "No dimensions found for table '{$tableIdentifier}' in compiled schema."
            );
        }

        return $this->dimensions[$tableIdentifier];
    }

    /**
     * Get all table identifiers on a specific provider connection.
     *
     * Example: getTablesOnConnection('eloquent:mysql') or getTablesOnConnection('eloquent:null')
     * Returns empty array if connection not found.
     *
     * @param string $connectionKey Provider:connection format (e.g., 'eloquent:mysql', 'eloquent:null')
     * @return array<string> Array of table identifiers
     */
    public function getTablesOnConnection(string $connectionKey): array
    {
        return $this->connectionIndex[$connectionKey] ?? [];
    }

    /**
     * Get all tables in the compiled schema.
     *
     * @return array<string, SliceDefinition> Map of identifier => definition
     */
    public function getAllTables(): array
    {
        return $this->tablesByIdentifier;
    }

    /**
     * Check if a table exists in the compiled schema.
     *
     * Checks both full identifiers and bare names.
     */
    public function hasTable(string $identifier): bool
    {
        return isset($this->tablesByIdentifier[$identifier])
            || isset($this->tablesByName[$identifier]);
    }

    /**
     * Get all connection names in the schema.
     *
     * @return array<string> Array of connection names
     */
    public function connections(): array
    {
        return array_keys($this->connectionIndex);
    }

    /**
     * Parse a metric reference into a MetricSource.
     *
     * Reference can be:
     * - 'orders.total' (bare table + column)
     * - 'eloquent:orders.total' (prefixed table + column)
     * - 'orders_eloquent.total' (alias notation, not recommended)
     *
     * @param  string  $reference  The metric reference to parse
     * @return MetricSource The resolved metric source
     *
     * @throws \InvalidArgumentException If reference cannot be parsed or resolved
     */
    public function parseMetricSource(string $reference): MetricSource
    {
        // Parse reference: {table}.{column} or {provider}:{table}.{column}
        $parts = explode('.', $reference, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(
                "Invalid metric reference '{$reference}'. Expected format: 'table.column' or 'provider:table.column'"
            );
        }

        [$tableReference, $column] = $parts;

        // Resolve table
        $table = $this->resolveTable($tableReference);
        if ($table === null) {
            throw new \InvalidArgumentException(
                "Cannot resolve metric reference '{$reference}': table '{$tableReference}' not found in schema"
            );
        }

        return new MetricSource($table, $column);
    }

    /**
     * Get the provider name for a table.
     *
     * @return string|null The provider name or null if table not found
     */
    public function getTableProvider(string $tableIdentifier): ?string
    {
        return $this->tableProviders[$tableIdentifier] ?? null;
    }

    /**
     * Get all unique provider connections used by tables matching a filter.
     *
     * Useful for validating that queries only use single connection.
     * Returns provider:connection composite keys.
     *
     * @param  array<MetricSource>  $metrics
     * @return array<string> Array of unique provider:connection keys
     */
    public function getConnectionsForMetrics(array $metrics): array
    {
        $connections = [];
        foreach ($metrics as $metric) {
            $provider = $metric->slice->provider();
            $connection = $metric->slice->connection();
            // Build composite key: provider:connection
            $key = $connection === null ? "$provider:null" : "$provider:$connection";
            $connections[$key] = true;
        }

        return array_keys($connections);
    }
}
