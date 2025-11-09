<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Contracts\TableContract;

/**
 * Resolves table joins needed for multi-table queries.
 *
 * The public API for join resolution. Coordinates JoinPathFinder and
 * JoinGraphBuilder to produce a database-agnostic join plan.
 */
final class JoinResolver
{
    public function __construct(
        private JoinPathFinder $pathFinder,
        private JoinGraphBuilder $graphBuilder,
    ) {}

    /**
     * Build a join plan for the given tables.
     *
     * @param  array<TableContract>  $tables
     */
    public function resolve(array $tables): JoinPlan
    {
        return $this->graphBuilder->build($tables);
    }
}
