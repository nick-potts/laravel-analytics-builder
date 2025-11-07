<?php

namespace NickPotts\Slice\Metrics;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;

class Sum extends Aggregation
{
    /**
     * Set up default configuration for Sum aggregation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Default to 2 decimal places
        $this->decimals = 2;
    }

    /**
     * Get the aggregation type.
     */
    public function aggregationType(): string
    {
        return 'sum';
    }

    /**
     * Apply SUM aggregation to the query.
     */
    public function applyToQuery(QueryAdapter $query, QueryDriver $driver, string $tableName, string $alias): void
    {
        $column = $this->column;

        // Use selectRaw with explicit alias
        $query->selectRaw("SUM({$tableName}.{$column}) as {$alias}");
    }
}
