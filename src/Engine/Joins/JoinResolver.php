<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Contracts\SliceSource;

/**
 * Resolves table joins needed for multi-table queries.
 *
 * The public API for join resolution. Uses JoinGraphBuilder (which internally
 * uses JoinPathFinder) to produce a database-agnostic join plan.
 */
final class JoinResolver
{
    public function __construct(
        private JoinGraphBuilder $graphBuilder,
    ) {}

    /**
     * Build a join plan for the given tables.
     *
     * @param  array<SliceSource>  $tables
     */
    public function resolve(array $tables): JoinPlan
    {
        return $this->graphBuilder->build($tables);
    }
}
