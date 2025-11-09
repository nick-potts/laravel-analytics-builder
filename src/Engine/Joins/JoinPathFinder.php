<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Finds shortest join paths between tables using BFS.
 *
 * Given a source and target table, uses breadth-first search through
 * the relation graphs to find the shortest path of joins needed.
 */
final class JoinPathFinder
{
    public function __construct(
        private SchemaProviderManager $manager,
    ) {}

    /**
     * Find shortest path from one table to another.
     *
     * Returns null if:
     * - No path exists between tables
     * - Tables are on different connections (cannot join)
     *
     * @return array<JoinSpecification>|null  Ordered list of joins or null if no path
     */
    public function find(
        TableContract $from,
        TableContract $to,
    ): ?array {
        $fromName = $from->name();
        $toName = $to->name();

        // Same table, no join needed
        if ($fromName === $toName) {
            return [];
        }

        // Tables must be on same connection to join
        if (! $this->sameConnection($from, $to)) {
            return null;
        }

        // BFS: queue items are [currentTable, pathSoFar]
        $queue = [[$from, []]];
        $visited = [$fromName => true];

        while (! empty($queue)) {
            [$currentTable, $path] = array_shift($queue);
            $currentName = $currentTable->name();

            // Explore all relations from current table
            foreach ($currentTable->relations()->all() as $relationName => $relation) {
                $targetModelClass = $relation->targetModel;
                $targetTable = $this->resolveTargetTable($targetModelClass);

                if ($targetTable === null) {
                    continue;
                }

                $targetName = $targetTable->name();

                // Skip if on different connection
                if (! $this->sameConnection($currentTable, $targetTable)) {
                    continue;
                }

                // Skip if already visited (prevents cycles)
                if (isset($visited[$targetName])) {
                    continue;
                }

                // Build join spec for this step
                $joinSpec = new JoinSpecification(
                    fromTable: $currentName,
                    toTable: $targetName,
                    relation: $relation,
                );

                // Add to path
                $newPath = [...$path, $joinSpec];

                // Found target!
                if ($targetName === $toName) {
                    return $newPath;
                }

                // Mark visited and enqueue for exploration
                $visited[$targetName] = true;
                $queue[] = [$targetTable, $newPath];
            }
        }

        // No path found
        return null;
    }

    /**
     * Check if two tables are on the same connection.
     *
     * Tables without explicit connections are assumed compatible.
     */
    private function sameConnection(TableContract $from, TableContract $to): bool
    {
        $fromConnection = $from->connection();
        $toConnection = $to->connection();

        // If either has no explicit connection, assume they're compatible
        if ($fromConnection === null || $toConnection === null) {
            return true;
        }

        // Both have explicit connections, must match
        return $fromConnection === $toConnection;
    }

    /**
     * Resolve a target model class to its table via schema manager.
     */
    private function resolveTargetTable(string $modelClass): ?TableContract
    {
        try {
            return $this->manager->resolve($modelClass);
        } catch (\Throwable) {
            // Model not registered or error resolving
            return null;
        }
    }
}
