<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Engine\Plans\DatabaseQueryPlan;
use NickPotts\Slice\Engine\Plans\QueryPlan;
use NickPotts\Slice\Engine\Plans\SoftwareJoinQueryPlan;

class QueryExecutor
{
    /**
     * Execute the query plan and return results.
     *
     * @return array<int, mixed>
     */
    public function run(QueryPlan $plan): array
    {
        if ($plan instanceof DatabaseQueryPlan) {
            return $plan->adapter()->execute();
        }

        if ($plan instanceof SoftwareJoinQueryPlan) {
            return $this->executeSoftwareJoinPlan($plan);
        }

        throw new \InvalidArgumentException('Unsupported query plan: '.get_class($plan));
    }

    protected function executeSoftwareJoinPlan(SoftwareJoinQueryPlan $plan): array
    {
        $tableResults = [];

        foreach ($plan->tablePlans() as $tableName => $tablePlan) {
            $tableResults[$tableName] = $this->normalizeRows($tablePlan->adapter()->execute());
        }

        $joinedRows = $this->performSoftwareJoins($plan, $tableResults);

        if (empty($joinedRows)) {
            return [];
        }

        $filteredRows = $this->applyDimensionFilters($joinedRows, $plan->dimensionFilters());

        if (empty($filteredRows)) {
            return [];
        }

        return $this->groupSoftwareResults($filteredRows, $plan);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tableResults
     * @return array<int, array<string, mixed>>
     */
    protected function performSoftwareJoins(SoftwareJoinQueryPlan $plan, array $tableResults): array
    {
        $primaryTable = $plan->primaryTable();
        $joinedRows = $tableResults[$primaryTable] ?? [];

        $joinedTables = [$primaryTable => true];
        $pendingRelations = $plan->relations();

        while (! empty($pendingRelations)) {
            $progress = false;

            foreach ($pendingRelations as $index => $relation) {
                $fromJoined = isset($joinedTables[$relation->from()]);
                $toJoined = isset($joinedTables[$relation->to()]);

                if ($fromJoined && $toJoined) {
                    unset($pendingRelations[$index]);
                    $progress = true;

                    continue;
                }

                if ($fromJoined xor $toJoined) {
                    if ($fromJoined) {
                        $joinedRows = $this->joinRows(
                            $joinedRows,
                            $tableResults[$relation->to()] ?? [],
                            $relation->fromAlias(),
                            $relation->toAlias()
                        );
                        $joinedTables[$relation->to()] = true;
                    } else {
                        $joinedRows = $this->joinRows(
                            $joinedRows,
                            $tableResults[$relation->from()] ?? [],
                            $relation->toAlias(),
                            $relation->fromAlias()
                        );
                        $joinedTables[$relation->from()] = true;
                    }

                    unset($pendingRelations[$index]);
                    $progress = true;
                }
            }

            if (! $progress) {
                throw new \RuntimeException('Unable to resolve software join plan. Verify relation definitions.');
            }
        }

        return $joinedRows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $leftRows
     * @param  array<int, array<string, mixed>>  $rightRows
     * @return array<int, array<string, mixed>>
     */
    protected function joinRows(array $leftRows, array $rightRows, string $leftAlias, string $rightAlias): array
    {
        if (empty($leftRows) || empty($rightRows)) {
            return [];
        }

        $index = [];
        foreach ($rightRows as $row) {
            if (! array_key_exists($rightAlias, $row)) {
                continue;
            }

            $index[$row[$rightAlias]][] = $row;
        }

        if (empty($index)) {
            return [];
        }

        $joined = [];

        foreach ($leftRows as $row) {
            $key = $row[$leftAlias] ?? null;

            if ($key === null || ! isset($index[$key])) {
                continue;
            }

            foreach ($index[$key] as $match) {
                $joined[] = array_merge($row, $match);
            }
        }

        return $joined;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function applyDimensionFilters(array $rows, array $filters): array
    {
        if (empty($filters)) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($filters) {
            foreach ($filters as $alias => $filter) {
                $value = $row[$alias] ?? null;

                if (isset($filter['only']) && ! in_array($value, $filter['only'], true)) {
                    return false;
                }

                if (isset($filter['except']) && in_array($value, $filter['except'], true)) {
                    return false;
                }

                if (isset($filter['where']) && ! $this->passesWhereFilter($value, $filter['where'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function groupSoftwareResults(array $rows, SoftwareJoinQueryPlan $plan): array
    {
        $dimensionOrder = $plan->dimensionOrder();
        $metricAliases = $plan->metricAliases();
        $joinAliases = array_flip($plan->joinAliases());

        $grouped = [];

        foreach ($rows as $row) {
            $groupKeyParts = [];

            foreach ($dimensionOrder as $alias) {
                $groupKeyParts[] = $row[$alias] ?? null;
            }

            $groupKey = $dimensionOrder ? json_encode($groupKeyParts) : '__all__';

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [];

                foreach ($dimensionOrder as $alias) {
                    $grouped[$groupKey][$alias] = $row[$alias] ?? null;
                }

                foreach ($metricAliases as $metricAlias) {
                    $grouped[$groupKey][$metricAlias] = 0;
                }
            }

            foreach ($metricAliases as $metricAlias) {
                if (! array_key_exists($metricAlias, $row) || ! is_numeric($row[$metricAlias])) {
                    continue;
                }

                $grouped[$groupKey][$metricAlias] += $row[$metricAlias] + 0;
            }
        }

        $results = array_values($grouped);

        foreach ($results as &$resultRow) {
            foreach ($metricAliases as $metricAlias) {
                if (! array_key_exists($metricAlias, $resultRow)) {
                    continue;
                }

                $resultRow[$metricAlias] = $this->normalizeNumericResult($resultRow[$metricAlias]);
            }

            foreach ($joinAliases as $alias => $_) {
                unset($resultRow[$alias]);
            }
        }

        unset($resultRow);

        // Sort results by dimension order to match database query ordering
        if (! empty($dimensionOrder)) {
            usort($results, function ($a, $b) use ($dimensionOrder) {
                foreach ($dimensionOrder as $alias) {
                    $aVal = $a[$alias] ?? null;
                    $bVal = $b[$alias] ?? null;

                    if ($aVal !== $bVal) {
                        return $aVal <=> $bVal;
                    }
                }

                return 0;
            });
        }

        return $results;
    }

    protected function passesWhereFilter(mixed $value, array $filter): bool
    {
        $operator = strtolower($filter['operator'] ?? '=');
        $target = $filter['value'] ?? null;

        return match ($operator) {
            '=', '==' => $value == $target,
            '!=', '<>' => $value != $target,
            '>' => $value > $target,
            '<' => $value < $target,
            '>=' => $value >= $target,
            '<=' => $value <= $target,
            default => $value == $target,
        };
    }

    protected function normalizeNumericResult(mixed $value): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        $numeric = (float) $value;

        return fmod($numeric, 1.0) === 0.0 ? (int) round($numeric) : $numeric;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRows(array $rows): array
    {
        return array_map(function ($row) {
            return is_array($row) ? $row : (array) $row;
        }, $rows);
    }
}
