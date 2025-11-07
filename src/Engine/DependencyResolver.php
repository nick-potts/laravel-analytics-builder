<?php

namespace NickPotts\Slice\Engine;

class DependencyResolver
{
    /**
     * Resolve metric dependencies and return them in topological order.
     * Computed metrics that depend on other metrics will come after their dependencies.
     *
     * @param array $normalizedMetrics Array from Slice::normalizeMetrics()
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
}

