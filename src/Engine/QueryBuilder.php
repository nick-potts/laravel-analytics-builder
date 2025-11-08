<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\AggregationMetric;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Builds and executes analytics queries.
 *
 * Current capabilities:
 * - Single-table queries
 * - Multiple metrics
 * - Multiple dimensions (GROUP BY)
 * - Time bucketing
 *
 * TODO (Phase 4+):
 * - Multi-table joins
 * - Base table resolution
 * - Computed metrics
 * - Software joins for cross-database queries
 */
class QueryBuilder
{
    public function __construct(
        protected SchemaProviderManager $providerManager,
        protected QueryDriver $driver
    ) {}

    /**
     * Build and execute a query.
     *
     * @param  array<AggregationMetric>  $metrics
     * @param  array<Dimension>  $dimensions
     * @return array Query results
     */
    public function build(array $metrics, array $dimensions): array
    {
        if (empty($metrics)) {
            return [];
        }

        // For now: Single-table queries only
        // TODO Phase 4: Handle multi-table queries with joins
        $table = $this->resolveTable($metrics[0]->table());

        // Create query for the table
        $query = $this->driver->query($table);

        // Add metric aggregations
        foreach ($metrics as $metric) {
            $alias = $metric->key();
            $metric->applyToQuery($query, $alias);
        }

        // Add dimensions (GROUP BY)
        if (! empty($dimensions)) {
            $dimensionResolver = new DimensionResolver($this->driver->grammar());

            foreach ($dimensions as $dimension) {
                $dimensionResolver->addDimensionToQuery($query, $dimension, $table);
            }
        }

        // Execute query
        return $query->get();
    }

    /**
     * Resolve a table name to TableContract.
     */
    protected function resolveTable(string $tableName): TableContract
    {
        return $this->providerManager->resolve($tableName);
    }
}
