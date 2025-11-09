<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Contracts\TableContract;

/**
 * Builds complete join graphs for multiple tables.
 *
 * Given a set of tables, uses a greedy approach to find paths that
 * connect all of them, deduplicating joins along the way.
 */
final class JoinGraphBuilder
{
    public function __construct(
        private JoinPathFinder $pathFinder,
    ) {}

    /**
     * Build complete join graph for multiple tables.
     *
     * Uses greedy approach: starts with first table, then iteratively
     * finds paths to unconnected tables, deduplicating joins.
     *
     * Returns empty plan if tables cannot be connected (e.g., different connections).
     *
     * @param  array<TableContract>  $tables
     */
    public function build(array $tables): JoinPlan
    {
        $plan = new JoinPlan;

        // Single table or none, no joins needed
        if (count($tables) <= 1) {
            return $plan;
        }

        // Start with first table as connected
        $connectedTables = [$tables[0]];
        $dedupeKeys = [];

        // Try to connect each remaining table
        for ($i = 1; $i < count($tables); $i++) {
            $targetTable = $tables[$i];

            // Try to find a path from any already-connected table
            foreach ($connectedTables as $sourceTable) {
                $path = $this->pathFinder->find($sourceTable, $targetTable);

                if ($path !== null) {
                    // Add all joins from path to plan (with deduplication)
                    foreach ($path as $joinSpec) {
                        $key = $joinSpec->fromTable.'->'.$joinSpec->toTable;

                        if (! isset($dedupeKeys[$key])) {
                            $dedupeKeys[$key] = true;
                            $plan->add($joinSpec);
                        }
                    }

                    // Mark this table as connected
                    $connectedTables[] = $targetTable;
                    break; // Found path, move to next target table
                }
            }

            // If no path found, table cannot be connected (maybe different connection)
            // Just skip it - executor will handle the fact that some tables couldn't be joined
        }

        return $plan;
    }
}
