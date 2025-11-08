<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryGrammar;
use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * Resolves dimensions to SQL GROUP BY clauses.
 *
 * Handles:
 * - Time bucketing (day, week, month, etc.)
 * - String dimensions
 * - Boolean dimensions
 * - Enum dimensions
 */
class DimensionResolver
{
    public function __construct(
        protected QueryGrammar $grammar
    ) {}

    /**
     * Add a dimension to the query.
     *
     * Adds both SELECT and GROUP BY clauses.
     */
    public function addDimensionToQuery(
        QueryAdapter $query,
        Dimension $dimension,
        TableContract $table
    ): void {
        $column = $dimension->column();
        $tableName = $table->name();
        $fullColumn = "{$tableName}.{$column}";

        if ($dimension instanceof TimeDimension) {
            // Use grammar to generate time bucketing SQL
            $expression = $this->grammar->formatTimeBucket($fullColumn, $dimension);
            $alias = $this->getDimensionAlias($dimension, $tableName);

            $query->selectRaw("{$expression} as {$alias}");
            $query->groupBy($expression); // MySQL requires GROUP BY on expression, not alias
            $query->orderBy($alias);
        } else {
            // Regular dimension - just select and group by column
            $alias = $this->getDimensionAlias($dimension, $tableName);

            $query->select("{$fullColumn} as {$alias}");
            $query->groupBy($fullColumn);
            $query->orderBy($alias);
        }
    }

    /**
     * Generate dimension alias for result columns.
     *
     * Format: {table}_{column}_{granularity}
     * Examples:
     * - orders_created_at_day
     * - customers_country
     * - products_is_active
     */
    protected function getDimensionAlias(Dimension $dimension, string $tableName): string
    {
        $column = $dimension->column();

        if ($dimension instanceof TimeDimension) {
            $granularity = $dimension->granularity();

            return "{$tableName}_{$column}_{$granularity}";
        }

        return "{$tableName}_{$column}";
    }
}
