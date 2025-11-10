<?php

namespace NickPotts\Slice;

use NickPotts\Slice\Engine\Joins\JoinResolver;
use NickPotts\Slice\Engine\QueryBuilder;
use NickPotts\Slice\Metrics\Aggregations\Aggregation;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Main Slice service class
 * Registered as 'slice' in the service container
 */
class SliceManager
{
    private SchemaProviderManager $manager;

    public function __construct(SchemaProviderManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Create a new query builder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            $this->manager,
            app(JoinResolver::class)
        );
    }

    /**
     * Normalize aggregations to MetricSource objects
     *
     * @param  array<Aggregation>  $aggregations
     * @return array<array{source: \Slice\Support\MetricSource, aggregation: Aggregation}>
     */
    public function normalizeMetrics(array $aggregations): array
    {
        $normalized = [];

        foreach ($aggregations as $aggregation) {
            $metricSource = $this->manager->parseMetricSource($aggregation->getReference());

            $normalized[] = [
                'source' => $metricSource,
                'aggregation' => $aggregation,
            ];
        }

        return $normalized;
    }

    /**
     * Get the schema provider manager
     */
    public function getManager(): SchemaProviderManager
    {
        return $this->manager;
    }
}
