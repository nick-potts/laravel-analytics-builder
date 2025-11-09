<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Contracts\SliceSource;
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
     * @return array<JoinSpecification>|null Ordered list of joins or null if no path
     */
    public function find(
        SliceSource $from,
        SliceSource $to,
    ): ?array {
        $fromName = $from->name();
        $toName = $to->name();
        $fromIdentifier = $from->identifier();
        $toIdentifier = $to->identifier();

        // Same table, no join needed
        if ($fromIdentifier === $toIdentifier) {
            return [];
        }

        // Tables must be on same connection to join
        if (! $this->sameConnection($from, $to)) {
            return null;
        }

        // BFS: queue items are [currentTable, pathSoFar]
        $queue = [[$from, []]];
        $visited = [$fromIdentifier => true];

        while (! empty($queue)) {
            [$currentTable, $path] = array_shift($queue);
            $currentName = $currentTable->name();
            $currentIdentifier = $currentTable->identifier();

            // Explore all relations from current table
            foreach ($currentTable->relations()->all() as $relationName => $relation) {
                $targetModelClass = $relation->targetModel;
                $targetTable = $this->resolveTargetTable($targetModelClass);

                if ($targetTable === null) {
                    continue;
                }

                $targetName = $targetTable->name();
                $targetIdentifier = $targetTable->identifier();

                // Skip if on different connection
                if (! $this->sameConnection($currentTable, $targetTable)) {
                    continue;
                }

                // Skip if already visited (prevents cycles)
                if (isset($visited[$targetIdentifier])) {
                    continue;
                }

                // Build join spec for this step
                $joinSpec = new JoinSpecification(
                    fromTable: $currentName,
                    toTable: $targetName,
                    relation: $relation,
                    fromIdentifier: $currentIdentifier,
                    toIdentifier: $targetIdentifier,
                );

                // Add to path
                $newPath = [...$path, $joinSpec];

                // Found target!
                if ($targetIdentifier === $toIdentifier) {
                    return $newPath;
                }

                // Mark visited and enqueue for exploration
                $visited[$targetIdentifier] = true;
                $queue[] = [$targetTable, $newPath];
            }
        }

        // No path found
        return null;
    }

    /**
     * Check if two tables are on the same connection.
     *
     * All tables must explicitly declare their connection (never null).
     * This prevents ambiguity about which database a table actually uses.
     */
    private function sameConnection(SliceSource $from, SliceSource $to): bool
    {
        return $from->connection() === $to->connection();
    }

    /**
     * Resolve a target model class to its table via schema manager.
     */
    private function resolveTargetTable(string $modelClass): ?SliceSource
    {
        try {
            return $this->manager->resolve($modelClass);
        } catch (\Throwable) {
            // Model not registered or error resolving
            return null;
        }
    }
}
