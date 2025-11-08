<?php

namespace NickPotts\Slice\Contracts;

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
     * Apply this aggregation to the query adapter.
     *
     * @param  QueryAdapter  $query  The query adapter
     * @param  string  $alias  The column alias to use
     */
    public function applyToQuery(QueryAdapter $query, string $alias): void;
}
