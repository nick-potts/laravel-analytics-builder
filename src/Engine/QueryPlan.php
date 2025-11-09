<?php

namespace NickPotts\Slice\Engine;

use Illuminate\Database\ConnectionInterface;
use NickPotts\Slice\Support\MetricSource;

/**
 * A query plan ready for execution
 *
 * Contains all the information needed to execute a query:
 * - Primary table for GROUP BY
 * - Tables involved
 * - Metrics to select
 * - Connection to use
 */
class QueryPlan
{
    public function __construct(
        public \NickPotts\Slice\Contracts\TableContract $primaryTable,
        public array $tables,
        public array $metrics,
        public ?ConnectionInterface $connection = null,
    ) {}

    /**
     * Get the primary table name
     */
    public function getPrimaryTableName(): string
    {
        return $this->primaryTable->name();
    }

    /**
     * Get all table names in the query
     *
     * @return string[]
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Get all metric sources
     *
     * @return MetricSource[]
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
