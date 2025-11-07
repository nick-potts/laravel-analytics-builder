<?php

namespace NickPotts\Slice\Metrics;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;

class Min extends Aggregation
{
    /**
     * Set up default configuration for Min aggregation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->decimals = 2;
    }

    /**
     * Get the aggregation type.
     */
    public function aggregationType(): string
    {
        return 'min';
    }

    /**
     * Apply MIN aggregation to the query.
     */
    public function applyToQuery(QueryAdapter $query, QueryDriver $driver, string $tableName, string $alias): void
    {
        $column = $this->column;
        $query->selectRaw("MIN({$tableName}.{$column}) as {$alias}");
    }
}
