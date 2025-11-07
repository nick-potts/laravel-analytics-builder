<?php

namespace NickPotts\Slice\Metrics;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;

class Count extends Aggregation
{
    /**
     * Set up default configuration for Count aggregation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Counts are always integers
        $this->decimals = 0;
    }

    /**
     * Get the aggregation type.
     */
    public function aggregationType(): string
    {
        return 'count';
    }

    /**
     * Apply COUNT aggregation to the query.
     */
    public function applyToQuery(QueryAdapter $query, QueryDriver $driver, string $tableName, string $alias): void
    {
        $column = $this->column;

        $query->selectRaw("COUNT({$tableName}.{$column}) as {$alias}");
    }
}
