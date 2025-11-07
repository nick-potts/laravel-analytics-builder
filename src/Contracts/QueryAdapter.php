<?php

namespace NickPotts\Slice\Contracts;

/**
 * Minimal interface that abstracts the underlying query builder implementation.
 */
interface QueryAdapter
{
    /**
     * Add a raw select expression to the query (include alias in the expression).
     */
    public function selectRaw(string $expression): void;

    /**
     * Apply a join to the query.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): void;

    /**
     * Add a GROUP BY column.
     */
    public function groupBy(string $column): void;

    /**
     * Add a raw GROUP BY expression.
     */
    public function groupByRaw(string $expression): void;

    /**
     * Apply a whereIn constraint.
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(string $column, array $values): void;

    /**
     * Apply a whereNotIn constraint.
     *
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $column, array $values): void;

    /**
     * Apply a basic where constraint.
     */
    public function where(string $column, string $operator, mixed $value): void;

    /**
     * Execute the query and return rows as an array.
     *
     * @return array<int, mixed>
     */
    public function execute(): array;

    /**
     * Expose the driver name (e.g. mysql, pgsql, clickhouse).
     */
    public function getDriverName(): string;

    /**
     * Access to the underlying native builder when needed for low-level operations.
     */
    public function getNative(): mixed;
}
