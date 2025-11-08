<?php

namespace NickPotts\Slice\Contracts;

/**
 * Represents a metric that can be queried.
 *
 * Metrics can be either:
 * - Aggregations (Sum, Count, Avg, etc.) - computed in SQL
 * - Computed metrics - derived from other metrics, computed post-execution
 */
interface Metric
{
    /**
     * Get the unique key for this metric.
     * Used as the result column alias.
     *
     * @return string e.g., 'orders_total', 'customers_count'
     */
    public function key(): string;

    /**
     * Get the metric type.
     *
     * @return string 'aggregation' or 'computed'
     */
    public function type(): string;

    /**
     * Convert metric to array representation.
     * Used for serialization and debugging.
     *
     * @return array{
     *     type: string,
     *     key: string,
     *     table: string,
     *     column: string,
     *     aggregation?: string,
     *     expression?: string,
     *     label?: string,
     *     format?: array
     * }
     */
    public function toArray(): array;
}
