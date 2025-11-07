<?php

namespace NickPotts\Slice\Contracts;

/**
 * Base contract for all metrics (enums and concrete aggregations).
 */
interface MetricContract
{
    /**
     * Get the metric definition (returns self for aggregations, or the underlying metric for enums).
     */
    public function get(): Metric;
}
