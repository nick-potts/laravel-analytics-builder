<?php

namespace NickPotts\Slice\Contracts;

use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * Database-specific SQL generation.
 *
 * Different databases have different syntax for:
 * - Time bucketing (DATE_TRUNC, DATE_FORMAT, strftime, etc.)
 * - Window functions
 * - CTEs
 * - Data types
 *
 * Grammar provides the database-specific SQL generation.
 */
interface QueryGrammar
{
    /**
     * Get the grammar name.
     *
     * @return string e.g., 'mysql', 'postgres', 'clickhouse'
     */
    public function name(): string;

    /**
     * Generate SQL for time bucketing.
     *
     * Converts a timestamp column to a bucketed value (day, week, month, etc.)
     *
     * @param  string  $column  The column name (e.g., 'orders.created_at')
     * @param  TimeDimension  $dimension  The time dimension with granularity
     * @return string SQL expression (e.g., "DATE_TRUNC('day', orders.created_at)")
     */
    public function formatTimeBucket(string $column, TimeDimension $dimension): string;

    /**
     * Wrap a value identifier (table name, column name) with proper quotes.
     *
     * MySQL: `table`, Postgres: "table", ClickHouse: `table`
     *
     * @param  string  $value  The identifier to wrap
     * @return string Wrapped identifier
     */
    public function wrap(string $value): string;
}
