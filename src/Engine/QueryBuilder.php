<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;
use NickPotts\Slice\Engine\Plans\DatabaseQueryPlan;
use NickPotts\Slice\Engine\Plans\QueryPlan;
use NickPotts\Slice\Engine\Plans\SoftwareJoinQueryPlan;
use NickPotts\Slice\Engine\Plans\SoftwareJoinRelation;
use NickPotts\Slice\Engine\Plans\SoftwareJoinTablePlan;
use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Tables\BelongsTo;
use NickPotts\Slice\Tables\CrossJoin;
use NickPotts\Slice\Tables\HasMany;

class QueryBuilder
{
    public function __construct(
        protected ?DependencyResolver $dependencies = null,
        protected ?JoinResolver $joinResolver = null,
        protected ?DimensionResolver $dimensionResolver = null,
        protected ?QueryDriver $driver = null,
    ) {
        $this->dependencies ??= new DependencyResolver;
        $this->joinResolver ??= new JoinResolver;
        $this->dimensionResolver ??= new DimensionResolver;

        if (! $this->driver) {
            $this->driver = function_exists('app') && app()->bound(QueryDriver::class)
                ? app(QueryDriver::class)
                : new LaravelQueryDriver;
        }
    }

    /**
     * Build a query plan from normalized metrics and dimensions.
     *
     * @param  array  $normalizedMetrics  Array from Slice::normalizeMetrics()
     * @param  array<Dimension>  $dimensions
     */
    public function build(array $normalizedMetrics, array $dimensions): QueryPlan
    {
        $tables = $this->extractTablesFromMetrics($normalizedMetrics);

        if (empty($tables)) {
            throw new \InvalidArgumentException('Unable to determine source tables for the requested metrics.');
        }

        // Split metrics by computation strategy
        $split = $this->dependencies->splitByComputationStrategy($normalizedMetrics);

        // If database CTEs aren't supported, treat database computed metrics as software
        if (! $this->driver->supportsCTEs() && $this->hasComputedMetrics($split['database'])) {
            // Move database computed metrics to software split
            foreach ($split['database'] as $key => $metricData) {
                $metricArray = $metricData['metric']->toArray();
                if ($metricArray['computed'] ?? false) {
                    $split['software'][] = $metricData;
                    unset($split['database'][$key]);
                }
            }
            $split['database'] = array_values($split['database']); // Re-index
        }

        // If we have cross-driver computed metrics, use software joins
        $needsSoftwareJoin = count($tables) > 1 && ! $this->driver->supportsDatabaseJoins();
        $hasSoftwareComputedMetrics = ! empty($split['software']);

        if ($needsSoftwareJoin || $hasSoftwareComputedMetrics) {
            // Use software join plan (PostProcessor will handle software CTEs)
            return $this->buildSoftwareJoinPlan($tables, $normalizedMetrics, $dimensions);
        }

        // Single driver - check if we can use database CTEs
        if ($this->driver->supportsCTEs() && $this->hasComputedMetrics($split['database'])) {
            return $this->buildWithCTEs($tables, $split['database'], $dimensions);
        }

        // Standard database plan (no CTEs needed)
        if (count($tables) === 1 || $this->driver->supportsDatabaseJoins()) {
            return $this->buildDatabasePlan($tables, $normalizedMetrics, $dimensions);
        }

        return $this->buildSoftwareJoinPlan($tables, $normalizedMetrics, $dimensions);
    }

    /**
     * Build a plan that executes entirely within the database/driver.
     *
     * @param  array<int, \NickPotts\Slice\Tables\Table>  $tables
     */
    protected function buildDatabasePlan(array $tables, array $normalizedMetrics, array $dimensions): DatabaseQueryPlan
    {
        $primaryTable = $tables[0];

        $query = $this->driver->createQuery($primaryTable->table());

        if (count($tables) > 1) {
            $joinPath = $this->joinResolver->buildJoinGraph($tables);
            $query = $this->joinResolver->applyJoins($query, $joinPath);
        }

        $this->addMetricSelects($query, $normalizedMetrics);

        if (! empty($dimensions)) {
            $this->addDimensionSelects($query, $dimensions, $tables);
            $this->addGroupBy($query, $dimensions, $tables);
            $this->addDimensionFilters($query, $dimensions, $tables);
            $this->addOrderBy($query, $dimensions, $tables);
        }

        return new DatabaseQueryPlan($query);
    }

    /**
     * Build a plan that joins data in software when the driver cannot do so directly.
     *
     * @param  array<int, \NickPotts\Slice\Tables\Table>  $tables
     */
    protected function buildSoftwareJoinPlan(array $tables, array $normalizedMetrics, array $dimensions): SoftwareJoinQueryPlan
    {
        $primaryTable = $tables[0];
        $primaryTableName = $primaryTable->table();

        // Pass dimensions to join resolver for dimension-based joins
        $this->joinResolver->setQueryDimensions($dimensions);
        $joinGraph = $this->joinResolver->buildJoinGraph($tables);

        if (empty($joinGraph)) {
            throw new \RuntimeException('Unable to determine join path for software join fallback.');
        }

        [$relations, $tableJoinColumns, $joinAliasNames] = $this->buildSoftwareJoinRelations($joinGraph, $dimensions);
        $joinAliasNames = array_values(array_unique($joinAliasNames));

        $dimensionOrder = $this->collectDimensionAliases($tables, $dimensions);
        $metricAliases = $this->collectMetricAliases($normalizedMetrics);
        $dimensionFilters = $this->collectDimensionFilters($tables, $dimensions);

        $tablePlans = [];

        foreach ($tables as $table) {
            $tableName = $table->table();
            $adapter = $this->driver->createQuery($tableName);

            foreach ($tableJoinColumns[$tableName] ?? [] as $joinColumn) {
                $adapter->selectRaw("{$tableName}.{$joinColumn['column']} as {$joinColumn['alias']}");
                $adapter->groupBy("{$tableName}.{$joinColumn['column']}");
            }

            if (! empty($dimensions)) {
                $this->addDimensionSelects($adapter, $dimensions, $tables, $tableName);
                $this->addGroupBy($adapter, $dimensions, $tables, $tableName);
            }

            $tableMetrics = $this->filterMetricsForTable($normalizedMetrics, $tableName);
            if (! empty($tableMetrics)) {
                $this->addMetricSelects($adapter, $tableMetrics);
            }

            $tablePlans[$tableName] = new SoftwareJoinTablePlan(
                $tableName,
                $adapter,
                $tableName === $primaryTableName,
            );
        }

        return new SoftwareJoinQueryPlan(
            $primaryTableName,
            $tablePlans,
            $relations,
            $dimensionOrder,
            $metricAliases,
            $dimensionFilters,
            $joinAliasNames,
        );
    }

    /**
     * Extract unique tables from normalized metrics.
     */
    protected function extractTablesFromMetrics(array $normalizedMetrics): array
    {
        $tables = [];
        $tableNames = [];

        foreach ($normalizedMetrics as $metricData) {
            $tableName = $metricData['table']->table();

            if (! in_array($tableName, $tableNames)) {
                $tables[] = $metricData['table'];
                $tableNames[] = $tableName;
            }
        }

        return $tables;
    }

    /**
     * Add metric select statements to the query.
     */
    protected function addMetricSelects(QueryAdapter $query, array $normalizedMetrics): void
    {
        foreach ($normalizedMetrics as $metricData) {
            $metric = $metricData['metric'];
            $table = $metricData['table'];
            $metricArray = $metric->toArray();

            // Skip computed metrics (will be calculated in post-processing)
            if ($metricArray['computed']) {
                continue;
            }

            $tableName = $table->table();
            $alias = $metricData['key'];

            // Use the metric's applyToQuery method if it implements DatabaseMetric
            if ($metric instanceof \NickPotts\Slice\Contracts\DatabaseMetric) {
                $metric->applyToQuery($query, $this->driver, $tableName, $alias);
            } else {
                throw new \InvalidArgumentException('Metric must implement DatabaseMetric interface');
            }
        }
    }

    /**
     * Add dimension select statements.
     *
     * @param  array<Dimension>  $dimensions
     */
    protected function addDimensionSelects(QueryAdapter $query, array $dimensions, array $tables, ?string $limitToTable = null): void
    {
        foreach ($dimensions as $dimension) {
            $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);

            // Validate granularity for time dimensions
            if ($dimension instanceof TimeDimension) {
                $this->dimensionResolver->validateGranularity($resolved, $dimension);
            }

            foreach ($resolved as $tableName => $resolvedData) {
                if ($limitToTable && $tableName !== $limitToTable) {
                    continue;
                }

                $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);

                // Handle time dimensions with granularity
                if ($dimension instanceof TimeDimension) {
                    $granularity = $dimension->getGranularity();
                    $selectExpr = $this->buildTimeDimensionSelect($tableName, $column, $granularity);
                    $alias = "{$tableName}_{$dimension->name()}_{$granularity}";
                } else {
                    $selectExpr = "{$tableName}.{$column}";
                    $alias = "{$tableName}_{$dimension->name()}";
                }

                $query->selectRaw("{$selectExpr} as {$alias}");
            }
        }
    }

    /**
     * Build SQL expression for time dimension with granularity.
     */
    protected function buildTimeDimensionSelect(string $table, string $column, string $granularity): string
    {
        return $this->driver->grammar()->formatTimeBucket($table, $column, $granularity);
    }

    /**
     * Add GROUP BY clauses for dimensions.
     *
     * @param  array<Dimension>  $dimensions
     */
    protected function addGroupBy(QueryAdapter $query, array $dimensions, array $tables, ?string $limitToTable = null): void
    {
        foreach ($dimensions as $dimension) {
            $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);

            foreach ($resolved as $tableName => $resolvedData) {
                if ($limitToTable && $tableName !== $limitToTable) {
                    continue;
                }

                $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);

                if ($dimension instanceof TimeDimension) {
                    $granularity = $dimension->getGranularity();
                    $groupExpr = $this->buildTimeDimensionSelect($tableName, $column, $granularity);
                    $query->groupByRaw($groupExpr);
                } else {
                    $query->groupBy("{$tableName}.{$column}");
                }
            }
        }
    }

    /**
     * Add WHERE filters from dimensions.
     *
     * @param  array<Dimension>  $dimensions
     */
    protected function addDimensionFilters(QueryAdapter $query, array $dimensions, array $tables, ?string $limitToTable = null): void
    {
        foreach ($dimensions as $dimension) {
            $filters = $dimension->filters();

            if (empty($filters)) {
                continue;
            }

            $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);

            foreach ($resolved as $tableName => $resolvedData) {
                if ($limitToTable && $tableName !== $limitToTable) {
                    continue;
                }

                $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);
                $fullColumn = "{$tableName}.{$column}";

                if (isset($filters['only'])) {
                    $query->whereIn($fullColumn, $filters['only']);
                }

                if (isset($filters['except'])) {
                    $query->whereNotIn($fullColumn, $filters['except']);
                }

                if (isset($filters['where'])) {
                    $operator = $filters['where']['operator'];
                    $value = $filters['where']['value'];
                    $query->where($fullColumn, $operator, $value);
                }
            }
        }
    }

    /**
     * Add ORDER BY clauses for dimensions to ensure consistent ordering.
     *
     * @param  array<Dimension>  $dimensions
     */
    protected function addOrderBy(QueryAdapter $query, array $dimensions, array $tables, ?string $limitToTable = null): void
    {
        foreach ($dimensions as $dimension) {
            $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);

            foreach ($resolved as $tableName => $resolvedData) {
                if ($limitToTable && $tableName !== $limitToTable) {
                    continue;
                }

                $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);

                if ($dimension instanceof TimeDimension) {
                    $granularity = $dimension->getGranularity();
                    $orderExpr = $this->buildTimeDimensionSelect($tableName, $column, $granularity);
                    $query->orderByRaw($orderExpr);
                } else {
                    $query->orderBy("{$tableName}.{$column}");
                }
            }
        }
    }

    /**
     * @param  array<int, \NickPotts\Slice\Tables\Table>  $tables
     * @param  array<Dimension>  $dimensions
     * @return array<int, string>
     */
    protected function collectDimensionAliases(array $tables, array $dimensions): array
    {
        $aliases = [];

        foreach ($dimensions as $dimension) {
            $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);

            foreach ($resolved as $tableName => $resolvedData) {
                $aliases[] = $this->dimensionAlias($tableName, $dimension);
            }
        }

        return $aliases;
    }

    /**
     * @param  array<int, \NickPotts\Slice\Tables\Table>  $tables
     * @param  array<Dimension>  $dimensions
     * @return array<string, array>
     */
    protected function collectDimensionFilters(array $tables, array $dimensions): array
    {
        $filters = [];

        foreach ($dimensions as $dimension) {
            $dimensionFilters = $dimension->filters();

            if (empty($dimensionFilters)) {
                continue;
            }

            $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);

            foreach ($resolved as $tableName => $resolvedData) {
                $filters[$this->dimensionAlias($tableName, $dimension)] = $dimensionFilters;
            }
        }

        return $filters;
    }

    /**
     * @return array<int, string>
     */
    protected function collectMetricAliases(array $normalizedMetrics): array
    {
        $aliases = [];

        foreach ($normalizedMetrics as $metricData) {
            $metricArray = $metricData['metric']->toArray();

            if (! ($metricArray['computed'] ?? false)) {
                $aliases[] = $metricData['key'];
            }
        }

        return $aliases;
    }

    protected function dimensionAlias(string $tableName, Dimension $dimension): string
    {
        if ($dimension instanceof TimeDimension) {
            return "{$tableName}_{$dimension->name()}_{$dimension->getGranularity()}";
        }

        return "{$tableName}_{$dimension->name()}";
    }

    protected function filterMetricsForTable(array $normalizedMetrics, string $tableName): array
    {
        return array_values(array_filter($normalizedMetrics, function ($metricData) use ($tableName) {
            if (! isset($metricData['table']) || ! $metricData['table']) {
                return false;
            }

            return $metricData['table']->table() === $tableName;
        }));
    }

    /**
     * Build join metadata for software joins.
     *
     * @param  array<int, array{from: string, to: string, relation: mixed}>  $joinGraph
     * @return array{
     *     array<int, SoftwareJoinRelation>,
     *     array<string, array<int, array{alias: string, column: string}>>,
     *     array<int, string>
     * }
     */
    protected function buildSoftwareJoinRelations(array $joinGraph, array $dimensions = []): array
    {
        $relations = [];
        $tableJoinColumns = [];
        $joinAliasNames = [];

        foreach ($joinGraph as $index => $join) {
            $relation = $join['relation'];
            $relationKey = $join['from'].'->'.$join['to'];

            // Handle dimension-based joins
            if ($relation === 'dimension_join') {
                // For dimension joins, we join on dimension aliases, not FK columns
                // So we don't add to $tableJoinColumns - dimensions are already selected
                $relations[] = new SoftwareJoinRelation(
                    $relationKey,
                    $join['from'],
                    $join['to'],
                    'dimension_join',
                    null, // No FK alias needed
                    null  // No FK alias needed
                );

                continue;
            }

            if ($relation instanceof BelongsTo) {
                $fromAlias = $this->joinAlias($relationKey, $join['from'], $relation->foreignKey(), (int) $index);
                $toAlias = $this->joinAlias($relationKey, $join['to'], $relation->ownerKey(), (int) $index);

                $tableJoinColumns[$join['from']][] = [
                    'alias' => $fromAlias,
                    'column' => $relation->foreignKey(),
                ];

                $tableJoinColumns[$join['to']][] = [
                    'alias' => $toAlias,
                    'column' => $relation->ownerKey(),
                ];

                $relations[] = new SoftwareJoinRelation(
                    $relationKey,
                    $join['from'],
                    $join['to'],
                    'belongs_to',
                    $fromAlias,
                    $toAlias,
                );

                $joinAliasNames[] = $fromAlias;
                $joinAliasNames[] = $toAlias;

                continue;
            }

            if ($relation instanceof HasMany) {
                $fromAlias = $this->joinAlias($relationKey, $join['from'], $relation->localKey(), (int) $index);
                $toAlias = $this->joinAlias($relationKey, $join['to'], $relation->foreignKey(), (int) $index);

                $tableJoinColumns[$join['from']][] = [
                    'alias' => $fromAlias,
                    'column' => $relation->localKey(),
                ];

                $tableJoinColumns[$join['to']][] = [
                    'alias' => $toAlias,
                    'column' => $relation->foreignKey(),
                ];

                $relations[] = new SoftwareJoinRelation(
                    $relationKey,
                    $join['from'],
                    $join['to'],
                    'has_many',
                    $fromAlias,
                    $toAlias,
                );

                $joinAliasNames[] = $fromAlias;
                $joinAliasNames[] = $toAlias;

                continue;
            }

            if ($relation instanceof CrossJoin) {
                $fromAlias = $this->joinAlias($relationKey, $join['from'], $relation->leftKey(), (int) $index);
                $toAlias = $this->joinAlias($relationKey, $join['to'], $relation->rightKey(), (int) $index);

                $tableJoinColumns[$join['from']][] = [
                    'alias' => $fromAlias,
                    'column' => $relation->leftKey(),
                ];

                $tableJoinColumns[$join['to']][] = [
                    'alias' => $toAlias,
                    'column' => $relation->rightKey(),
                ];

                $relations[] = new SoftwareJoinRelation(
                    $relationKey,
                    $join['from'],
                    $join['to'],
                    'cross_join',
                    $fromAlias,
                    $toAlias,
                );

                $joinAliasNames[] = $fromAlias;
                $joinAliasNames[] = $toAlias;
            }
        }

        return [$relations, $tableJoinColumns, $joinAliasNames];
    }

    protected function joinAlias(string $relationKey, string $tableName, string $column, int $index): string
    {
        $relationKey = $this->sanitizeAliasSegment($relationKey);
        $tableName = $this->sanitizeAliasSegment($tableName);
        $column = $this->sanitizeAliasSegment($column);

        return "__join_{$index}_{$relationKey}_{$tableName}_{$column}";
    }

    protected function sanitizeAliasSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', $segment);

        return $clean === null ? $segment : $clean;
    }

    /**
     * Build a query plan using CTEs for layered computed metrics.
     *
     * @param  array<int, \NickPotts\Slice\Tables\Table>  $tables
     */
    protected function buildWithCTEs(array $tables, array $normalizedMetrics, array $dimensions): DatabaseQueryPlan
    {
        // Group metrics by dependency level
        $levels = $this->dependencies->groupByLevel($normalizedMetrics);

        // Create base query from driver
        $query = $this->driver->createQuery();

        // Build CTEs layer by layer
        $previousCTE = null;

        foreach ($levels as $levelIndex => $levelMetrics) {
            $cteName = "level_{$levelIndex}";

            if ($levelIndex === 0) {
                // Base level: Build aggregation query with joins
                $cteQuery = $this->buildBaseAggregationCTE($tables, $levelMetrics, $dimensions);
            } else {
                // Computed level: Build from previous CTE
                $cteQuery = $this->buildComputedCTE($previousCTE, $levelMetrics, $dimensions);
            }

            $query->withExpression($cteName, $cteQuery);
            $previousCTE = $cteName;
        }

        // Final SELECT from last CTE
        $query->from($previousCTE);
        $query->select('*');

        return new DatabaseQueryPlan($query);
    }

    /**
     * Build base CTE with aggregations and joins.
     */
    protected function buildBaseAggregationCTE(array $tables, array $metrics, array $dimensions): QueryAdapter
    {
        $primaryTable = $tables[0];
        $query = $this->driver->createQuery($primaryTable->table());

        // Add joins
        if (count($tables) > 1) {
            $joinPath = $this->joinResolver->buildJoinGraph($tables);
            $query = $this->joinResolver->applyJoins($query, $joinPath);
        }

        // Add metric selects
        $this->addMetricSelects($query, $metrics);

        // Add dimension selects and group by
        if (! empty($dimensions)) {
            $this->addDimensionSelects($query, $dimensions, $tables);
            $this->addGroupBy($query, $dimensions, $tables);
            $this->addDimensionFilters($query, $dimensions, $tables);
            $this->addOrderBy($query, $dimensions, $tables);
        }

        return $query;
    }

    /**
     * Build computed CTE from previous CTE.
     */
    protected function buildComputedCTE(string $fromCTE, array $metrics, array $dimensions): QueryAdapter
    {
        $query = $this->driver->createQuery($fromCTE);

        // Select all existing columns
        $query->select('*');

        // Add computed metric expressions
        foreach ($metrics as $metricData) {
            $metric = $metricData['metric'];
            $metricArray = $metric->toArray();

            if ($metricArray['computed']) {
                $expression = $this->translateComputedExpression(
                    $metricArray['expression'],
                    $metricArray['dependencies']
                );

                $alias = $metricData['key'];
                $query->selectRaw("{$expression} as {$alias}");
            }
        }

        return $query;
    }

    /**
     * Translate computed metric expression to use column aliases.
     */
    protected function translateComputedExpression(string $expression, array $dependencies): string
    {
        // Replace metric keys with actual column aliases
        // e.g., "revenue - cost" → "orders_revenue - orders_cost"

        $translated = $expression;

        foreach ($dependencies as $depKey) {
            // Convert "orders.revenue" → "orders_revenue"
            $columnAlias = str_replace('.', '_', $depKey);

            // Replace in expression (handle word boundaries)
            $translated = preg_replace(
                '/\b'.preg_quote($depKey, '/').'\b/',
                $columnAlias,
                $translated
            );
        }

        // SQLite specific: Force float division and float result type
        if ($this->driver->grammar() instanceof \NickPotts\Slice\Engine\Grammar\SqliteGrammar) {
            // Handle different division patterns to force float division

            // Case 1: ) / → ) * 1.0 / (parenthesized expressions)
            $translated = preg_replace('/\)\s*\//', ') * 1.0 /', $translated);

            // Case 2: identifier / → (identifier * 1.0) / (simple identifiers)
            $translated = preg_replace('/\b([a-z_][a-z0-9_]*)\s*\//', '($1 * 1.0) /', $translated);

            // Case 3: number / → (number * 1.0) / (numeric literals)
            $translated = preg_replace('/\b(\d+(?:\.\d+)?)\s*\//', '($1 * 1.0) /', $translated);

            // Ensure the final result is a REAL type (float) by adding 0.0
            // This forces SQLite and PDO to treat the result as float, not integer
            $translated = "({$translated}) + 0.0";
        }

        return $translated;
    }

    /**
     * Check if metrics array has any computed metrics.
     */
    protected function hasComputedMetrics(array $normalizedMetrics): bool
    {
        foreach ($normalizedMetrics as $metricData) {
            $metricArray = $metricData['metric']->toArray();
            if ($metricArray['computed'] ?? false) {
                return true;
            }
        }

        return false;
    }
}
