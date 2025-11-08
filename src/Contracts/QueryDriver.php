<?php

namespace NickPotts\Slice\Contracts;

/**
 * Abstraction for database-specific query execution.
 *
 * Different data sources require different query strategies:
 * - Laravel: Uses Query Builder
 * - ClickHouse: Uses HTTP/TCP client
 * - HTTP APIs: Uses HTTP client
 *
 * Each driver knows how to create and execute queries for its target system.
 */
interface QueryDriver
{
    /**
     * Get the driver name.
     *
     * @return string e.g., 'laravel', 'clickhouse', 'http'
     */
    public function name(): string;

    /**
     * Create a new query for the given table.
     *
     * @param  TableContract  $table  The table to query
     * @return QueryAdapter An adapter wrapping the query
     */
    public function query(TableContract $table): QueryAdapter;

    /**
     * Check if this driver supports database joins.
     *
     * @return bool True if driver can execute JOINs, false if software joins needed
     */
    public function supportsJoins(): bool;

    /**
     * Check if this driver supports CTEs (Common Table Expressions).
     *
     * @return bool True if driver supports WITH clauses
     */
    public function supportsCTEs(): bool;

    /**
     * Get the grammar for this driver.
     * Grammar handles database-specific SQL generation.
     */
    public function grammar(): QueryGrammar;
}
