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
        $dimensionAliases = $plan->dimensionOrder(); // Get dimension aliases for dimension joins

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
                    // For dimension joins, use dimension aliases instead of FK aliases
                    if ($relation->type() === 'dimension_join') {
                        $fromAlias = $this->findDimensionAlias($dimensionAliases, $relation->from());
                        $toAlias = $this->findDimensionAlias($dimensionAliases, $relation->to());
                    } else {
                        $fromAlias = $relation->fromAlias();
                        $toAlias = $relation->toAlias();
                    }

                    $preserveLeftRows = $relation->type() === 'dimension_join';

                    if ($fromJoined) {
                        $joinedRows = $this->joinRows(
                            $joinedRows,
                            $tableResults[$relation->to()] ?? [],
                            $fromAlias,
                            $toAlias,
                            $preserveLeftRows
                        );
                        $joinedTables[$relation->to()] = true;
                    } else {
                        $joinedRows = $this->joinRows(
                            $joinedRows,
                            $tableResults[$relation->from()] ?? [],
                            $toAlias,
                            $fromAlias,
                            $preserveLeftRows
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
     * Find the dimension alias for a given table.
     * Returns the first dimension alias that starts with the table name.
     */
    protected function findDimensionAlias(array $dimensionAliases, string $tableName): ?string
    {
        foreach ($dimensionAliases as $alias) {
            if (str_starts_with($alias, $tableName.'_')) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $leftRows
     * @param  array<int, array<string, mixed>>  $rightRows
     * @return array<int, array<string, mixed>>
     */
    protected function joinRows(
        array $leftRows,
        array $rightRows,
        string $leftAlias,
        string $rightAlias,
        bool $preserveUnmatchedLeftRows = false
    ): array {
        if (empty($leftRows)) {
            return [];
        }

        if (empty($rightRows)) {
            return $preserveUnmatchedLeftRows ? $leftRows : [];
        }

        $index = [];
        foreach ($rightRows as $row) {
            if (! array_key_exists($rightAlias, $row)) {
                continue;
            }

            $normalizedKey = $this->normalizeJoinValue($row[$rightAlias]);

            if ($normalizedKey === null) {
                continue;
            }

            $index[$normalizedKey][] = $row;
        }

        if (empty($index)) {
            return $preserveUnmatchedLeftRows ? $leftRows : [];
        }

        $joined = [];

        foreach ($leftRows as $row) {
            $key = array_key_exists($leftAlias, $row)
                ? $this->normalizeJoinValue($row[$leftAlias])
                : null;

            if ($key === null || ! isset($index[$key])) {
                if ($preserveUnmatchedLeftRows) {
                    $joined[] = $row;
                }

                continue;
            }

            foreach ($index[$key] as $match) {
                $joined[] = array_merge($row, $match);
            }
        }

        return $joined;
    }

    protected function normalizeJoinValue(mixed $value): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($this->looksLikeDateString($trimmed)) {
                try {
                    $date = new \DateTimeImmutable($trimmed);

                    return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
                } catch (\Exception $e) {
                    // Fall through to returning the trimmed string
                }
            }

            return $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $value;
    }

    protected function looksLikeDateString(string $value): bool
    {
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}(?::?\d{2})?)?)?$/',
            $value
        );
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
