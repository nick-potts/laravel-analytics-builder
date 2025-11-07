<?php

namespace NickPotts\Slice\Metrics;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;

class Avg extends Aggregation
{
    /**
     * Set up default configuration for Avg aggregation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Averages typically need decimal precision
        $this->decimals = 2;
    }

    /**
     * Get the aggregation type.
     */
    public function aggregationType(): string
    {
        return 'avg';
    }

    /**
     * Apply AVG aggregation to the query.
     */
    public function applyToQuery(QueryAdapter $query, QueryDriver $driver, string $tableName, string $alias): void
    {
        $column = $this->column;

        $query->selectRaw("AVG({$tableName}.{$column}) as {$alias}");
    }
}
