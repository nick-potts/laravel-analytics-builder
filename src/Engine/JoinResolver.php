<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Tables\BelongsTo;
use NickPotts\Slice\Tables\HasMany;
use NickPotts\Slice\Tables\Table;

class JoinResolver
{
    /**
     * Find the join path between two tables using BFS.
     *
     * @param  array<Table>  $allTables
     * @return array<array{from: string, to: string, relation: mixed}>|null
     */
    public function findJoinPath(Table $from, Table $to, array $allTables): ?array
    {
        if ($from->table() === $to->table()) {
            return [];
        }

        // BFS to find shortest join path
        $queue = [[$from, []]];
        $visited = [$from->table() => true];

        while (! empty($queue)) {
            [$currentTable, $path] = array_shift($queue);

            foreach ($currentTable->relations() as $relationName => $relation) {
                $relatedTableClass = $relation->table();
                $relatedTable = new $relatedTableClass;
                $relatedTableName = $relatedTable->table();

                if (isset($visited[$relatedTableName])) {
                    continue;
                }

                $newPath = array_merge($path, [[
                    'from' => $currentTable->table(),
                    'to' => $relatedTableName,
                    'relation' => $relation,
                ]]);

                if ($relatedTableName === $to->table()) {
                    return $newPath;
                }

                $visited[$relatedTableName] = true;
                $queue[] = [$relatedTable, $newPath];
            }
        }

        return null;
    }

    /**
     * Apply joins to a query adapter based on the join path.
     *
     * @param  array<array{from: string, to: string, relation: mixed}>  $joinPath
     */
    public function applyJoins(QueryAdapter $query, array $joinPath): QueryAdapter
    {
        foreach ($joinPath as $join) {
            $relation = $join['relation'];
            $fromTable = $join['from'];
            $toTable = $join['to'];

            if ($relation instanceof BelongsTo) {
                $query->join(
                    $toTable,
                    "{$fromTable}.{$relation->foreignKey()}",
                    '=',
                    "{$toTable}.{$relation->ownerKey()}"
                );
            } elseif ($relation instanceof HasMany) {
                $query->join(
                    $toTable,
                    "{$fromTable}.{$relation->localKey()}",
                    '=',
                    "{$toTable}.{$relation->foreignKey()}"
                );
            }
            // Add support for other relation types as needed
        }

        return $query;
    }

    /**
     * Build a join graph connecting all required tables.
     *
     * @param  array<Table>  $tables
     * @return array<array{from: string, to: string, relation: mixed}>
     */
    public function buildJoinGraph(array $tables): array
    {
        if (count($tables) <= 1) {
            return [];
        }

        $allJoins = [];
        $connectedTables = [$tables[0]->table()];

        // Connect remaining tables to the graph
        for ($i = 1; $i < count($tables); $i++) {
            $targetTable = $tables[$i];

            // Try to find a path from any connected table to the target
            foreach ($tables as $sourceTable) {
                if (in_array($sourceTable->table(), $connectedTables) && $sourceTable->table() !== $targetTable->table()) {
                    $path = $this->findJoinPath($sourceTable, $targetTable, $tables);

                    if ($path !== null) {
                        foreach ($path as $join) {
                            // Avoid duplicate joins
                            $joinKey = $join['from'].'->'.$join['to'];
                            if (! isset($allJoins[$joinKey])) {
                                $allJoins[$joinKey] = $join;
                            }
                        }

                        $connectedTables[] = $targetTable->table();
                        break;
                    }
                }
            }
        }

        return array_values($allJoins);
    }
}
