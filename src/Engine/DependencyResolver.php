<?php

namespace NickPotts\Slice\Engine;

class DependencyResolver
{
    /**
     * Resolve metric dependencies and return them in topological order.
     * Computed metrics that depend on other metrics will come after their dependencies.
     *
     * @param  array  $normalizedMetrics  Array from Slice::normalizeMetrics()
     * @return array Same format, but sorted by dependencies
     */
    public function resolve(array $normalizedMetrics): array
    {
        $resolved = [];
        $unresolved = [];
        $graph = $this->buildDependencyGraph($normalizedMetrics);

        foreach ($normalizedMetrics as $metricData) {
            $this->resolveDependencies($metricData, $graph, $resolved, $unresolved, $normalizedMetrics);
        }

        return $resolved;
    }

    /**
     * Build a dependency graph from normalized metrics.
     */
    protected function buildDependencyGraph(array $normalizedMetrics): array
    {
        $graph = [];

        foreach ($normalizedMetrics as $metricData) {
            $key = $metricData['key'];
            $metricArray = $metricData['metric']->toArray();
            $dependencies = $metricArray['dependencies'] ?? [];

            $graph[$key] = $dependencies;
        }

        return $graph;
    }

    /**
     * Recursively resolve dependencies using DFS.
     */
    protected function resolveDependencies(
        array $metricData,
        array $graph,
        array &$resolved,
        array &$unresolved,
        array $allMetrics
    ): void {
        $key = $metricData['key'];

        // Already resolved
        if (isset($resolved[$key])) {
            return;
        }

        // Circular dependency detected
        if (isset($unresolved[$key])) {
            throw new \RuntimeException("Circular dependency detected for metric: {$key}");
        }

        $unresolved[$key] = true;

        // Resolve dependencies first
        $dependencies = $graph[$key] ?? [];
        foreach ($dependencies as $dependencyKey) {
            // Find the dependency in all metrics
            $dependencyMetric = null;
            foreach ($allMetrics as $m) {
                if ($m['key'] === $dependencyKey) {
                    $dependencyMetric = $m;
                    break;
                }
            }

            if ($dependencyMetric) {
                $this->resolveDependencies($dependencyMetric, $graph, $resolved, $unresolved, $allMetrics);
            }
        }

        // Add to resolved
        unset($unresolved[$key]);
        $resolved[$key] = $metricData;
    }

    /**
     * Group metrics by dependency level for CTE generation.
     * Returns array indexed by level (0 = base aggregations, 1+ = computed).
     *
     * @param  array  $normalizedMetrics  Array from Slice::normalizeMetrics()
     * @return array<int, array> Metrics grouped by level
     */
    public function groupByLevel(array $normalizedMetrics): array
    {
        $levels = [];

        foreach ($normalizedMetrics as $metricData) {
            $level = $this->calculateLevel($metricData, $normalizedMetrics, []);
            $levels[$level][] = $metricData;
        }

        ksort($levels);

        return $levels;
    }

    /**
     * Split metrics into database-computable vs software-computable.
     *
     * Database-computable: All dependencies from same table/driver
     * Software-computable: Has cross-table or cross-driver dependencies
     *
     * @param  array  $normalizedMetrics  Array from Slice::normalizeMetrics()
     * @return array{database: array, software: array}
     */
    public function splitByComputationStrategy(array $normalizedMetrics): array
    {
        $database = [];
        $software = [];

        foreach ($normalizedMetrics as $metricData) {
            $metricArray = $metricData['metric']->toArray();

            // Base metrics (no dependencies) go to database
            if (empty($metricArray['dependencies'])) {
                $database[] = $metricData;

                continue;
            }

            // Check if all dependencies are from same table
            if ($this->canComputeInDatabase($metricData, $normalizedMetrics)) {
                $database[] = $metricData;
            } else {
                $software[] = $metricData;
            }
        }

        return [
            'database' => $database,
            'software' => $software,
        ];
    }

    /**
     * Check if a computed metric can be calculated in the database.
     */
    protected function canComputeInDatabase(array $metricData, array $allMetrics): bool
    {
        $metricArray = $metricData['metric']->toArray();
        $dependencies = $metricArray['dependencies'] ?? [];

        if (empty($dependencies)) {
            return true;
        }

        // Get the table for this metric
        $metricTable = $metricData['table']->table();

        // Check if all dependencies are from the same table
        foreach ($dependencies as $depKey) {
            $depMetric = $this->findMetricByKey($allMetrics, $depKey);

            if (! $depMetric) {
                return false;
            }

            // If dependency is from different table, needs software computation
            if ($depMetric['table']->table() !== $metricTable) {
                return false;
            }

            // If dependency itself needs software computation, this does too
            $depArray = $depMetric['metric']->toArray();
            if (! empty($depArray['dependencies']) && ! $this->canComputeInDatabase($depMetric, $allMetrics)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate the dependency level for a metric.
     * Level 0 = no dependencies, Level 1+ = depends on other metrics.
     */
    protected function calculateLevel(array $metricData, array $allMetrics, array $visited): int
    {
        $key = $metricData['key'];

        // Prevent infinite recursion
        if (isset($visited[$key])) {
            throw new \RuntimeException("Circular dependency detected for: {$key}");
        }

        $visited[$key] = true;

        $metricArray = $metricData['metric']->toArray();
        $dependencies = $metricArray['dependencies'] ?? [];

        // Base metrics have level 0
        if (empty($dependencies)) {
            return 0;
        }

        // Computed metrics: level = max(dependency levels) + 1
        $maxLevel = 0;
        foreach ($dependencies as $depKey) {
            $depMetric = $this->findMetricByKey($allMetrics, $depKey);
            if ($depMetric) {
                $depLevel = $this->calculateLevel($depMetric, $allMetrics, $visited);
                $maxLevel = max($maxLevel, $depLevel + 1);
            }
        }

        return $maxLevel;
    }

    /**
     * Find a metric by its key in the normalized metrics array.
     */
    protected function findMetricByKey(array $metrics, string $key): ?array
    {
        foreach ($metrics as $metric) {
            if ($metric['key'] === $key) {
                return $metric;
            }
        }

        return null;
    }
}
