<?php

namespace NickPotts\Slice\Contracts;

/**
 * Contract for metrics that apply SQL aggregations to the database.
 */
interface DatabaseMetric extends Metric
{
    /**
     * Apply this metric's aggregation to the query via the provided driver.
     *
     * @param QueryAdapter $query The query adapter to mutate
     * @param QueryDriver $driver The driver handling SQL grammar
     * @param string $tableName The table name to aggregate from
     * @param string $alias The alias to use for the result column
     */
    public function applyToQuery(QueryAdapter $query, QueryDriver $driver, string $tableName, string $alias): void;
}
