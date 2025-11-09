<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\Joins\JoinPlan;
use NickPotts\Slice\Support\MetricSource;

/**
 * A query plan ready for execution
 *
 * Contains all the information needed to execute a query:
 * - Primary table for GROUP BY
 * - Tables involved
 * - Metrics to select
 * - Join plan to connect multiple tables
 *
 * Note: Connection information is stored in tables and will be resolved
 * by the executor/adapter layer based on the driver type (eloquent:, clickhouse:, http:, etc.)
 */
class QueryPlan
{
    public function __construct(
        public SliceSource $primaryTable,
        public array $tables,
        public array $metrics,
        public JoinPlan $joinPlan,
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
        $names = array_map(
            fn (SliceSource $table) => $table->name(),
            $this->tables
        );

        return array_values($names);
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
