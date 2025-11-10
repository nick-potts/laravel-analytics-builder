<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Support\CompiledSchema;

/**
 * Finds shortest join paths between tables using BFS.
 *
 * Given a source and target table, uses breadth-first search through
 * the relation graphs to find the shortest path of joins needed.
 *
 * Accepts CompiledSchema for O(1) relation lookups, avoiding per-query
 * redundant schema resolution.
 */
final class JoinPathFinder
{
    public function __construct(
        private CompiledSchema $schema,
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
                $targetIdentifier = $relation->targetTableIdentifier;
                $targetTable = $this->schema->resolveTable($targetIdentifier);

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
     * Check if two tables are on the same connection and provider.
     *
     * Both provider and connection must match. If either connection is null,
     * they're treated as using the provider's default and must both be null.
     */
    private function sameConnection(SliceSource $from, SliceSource $to): bool
    {
        return $from->provider() === $to->provider()
            && $from->connection() === $to->connection();
    }

}
