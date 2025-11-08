<?php

namespace NickPotts\Slice\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * Represents an aggregation metric computed in SQL (SUM, COUNT, AVG, etc.).
 */
interface AggregationMetric extends Metric
{
    /**
     * Get the table name this metric aggregates from.
     */
    public function table(): string;

    /**
     * Get the column name to aggregate.
     */
    public function column(): string;

    /**
     * Get the aggregation function.
     *
     * @return string 'sum', 'count', 'avg', 'min', 'max', etc.
     */
    public function aggregation(): string;

    /**
     * Apply this aggregation to the query builder.
     *
     * @param  Builder  $query  The Laravel query builder
     * @param  string  $alias  The column alias to use
     */
    public function applyToQuery(Builder $query, string $alias): void;
}
