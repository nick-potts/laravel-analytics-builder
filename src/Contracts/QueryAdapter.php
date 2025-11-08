<?php

namespace NickPotts\Slice\Contracts;

/**
 * Uniform interface for query building across different databases.
 *
 * Wraps the underlying query builder (Laravel Query Builder, ClickHouse client, etc.)
 * with a consistent API that the query engine can use.
 */
interface QueryAdapter
{
    /**
     * Add a SELECT clause.
     *
     * @param  string|array  $columns  Column(s) to select
     */
    public function select(string|array $columns): static;

    /**
     * Add a raw SELECT clause.
     *
     * @param  string  $expression  Raw SQL expression
     */
    public function selectRaw(string $expression): static;

    /**
     * Add a JOIN clause.
     *
     * @param  string  $table  Table to join
     * @param  string  $first  First column in join condition
     * @param  string  $operator  Comparison operator
     * @param  string  $second  Second column in join condition
     */
    public function join(string $table, string $first, string $operator, string $second): static;

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static;

    /**
     * Add a WHERE clause.
     *
     * @param  string  $column  Column name
     * @param  mixed  $operator  Operator or value if operator is '='
     * @param  mixed  $value  Value to compare (if operator provided)
     */
    public function where(string $column, mixed $operator, mixed $value = null): static;

    /**
     * Add a raw WHERE clause.
     */
    public function whereRaw(string $sql, array $bindings = []): static;

    /**
     * Add a WHERE NULL clause.
     */
    public function whereNull(string $column): static;

    /**
     * Add a GROUP BY clause.
     *
     * @param  string|array  $columns  Column(s) to group by
     */
    public function groupBy(string|array $columns): static;

    /**
     * Add an ORDER BY clause.
     *
     * @param  string  $column  Column to order by
     * @param  string  $direction  'asc' or 'desc'
     */
    public function orderBy(string $column, string $direction = 'asc'): static;

    /**
     * Add a raw ORDER BY clause.
     */
    public function orderByRaw(string $sql): static;

    /**
     * Add a CTE (Common Table Expression).
     *
     * @param  string  $name  CTE name
     * @param  QueryAdapter|string  $query  Query or raw SQL
     */
    public function withExpression(string $name, QueryAdapter|string $query): static;

    /**
     * Execute the query and get all results.
     *
     * @return array Array of result rows
     */
    public function get(): array;

    /**
     * Get the underlying query builder.
     * Useful for driver-specific operations.
     */
    public function getQuery(): mixed;

    /**
     * Convert the query to SQL string (for debugging).
     */
    public function toSql(): string;
}
