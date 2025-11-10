<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\Joins\JoinResolver;
use NickPotts\Slice\Metrics\Aggregations\Aggregation;
use NickPotts\Slice\Support\MetricSource;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Builds query plans from normalized metrics
 *
 * Takes MetricSource objects from SchemaProviderManager and builds
 * a QueryPlan that can be executed.
 */
class QueryBuilder
{
    private SchemaProviderManager $manager;

    private JoinResolver $joinResolver;

    /**
     * Metrics to include in the query, keyed by their source
     *
     * @var array<string, MetricSource>
     */
    private array $metrics = [];

    /**
     * Tables involved in the query (de-duped)
     *
     * @var array<string, SliceSource>
     */
    private array $tables = [];

    /**
     * Connection identifier to use (if multi-table, all must be on same connection)
     */
    private ?string $connection = null;

    public function __construct(SchemaProviderManager $manager, JoinResolver $joinResolver)
    {
        $this->manager = $manager;
        $this->joinResolver = $joinResolver;
    }

    /**
     * Add normalized metrics to the query
     *
     * @param  array<array{source: MetricSource, aggregation: Aggregation}>  $normalizedMetrics
     */
    public function addMetrics(array $normalizedMetrics): self
    {
        foreach ($normalizedMetrics as $item) {
            $source = $item['source'];
            $key = $source->key();

            $this->metrics[$key] = $source;

            // Extract table and validate connection consistency
            $slice = $source->slice;
            $resolvedConnection = $slice->connection();

            $sliceKey = $slice->identifier();
            $isNewTable = ! isset($this->tables[$sliceKey]);
            if ($isNewTable) {
                $this->tables[$sliceKey] = $slice;
            }

            if ($this->connection === null) {
                $this->connection = $resolvedConnection;
            } elseif ($isNewTable && $this->connection !== $resolvedConnection) {
                throw new \RuntimeException(
                    'Cannot mix tables from different connections: '.
                    $this->connection.' and '.$resolvedConnection
                );
            }
        }

        return $this;
    }

    /**
     * Build the query plan
     */
    public function build(): QueryPlan
    {
        if (empty($this->tables)) {
            throw new \RuntimeException('No tables selected for query');
        }

        $primaryTable = reset($this->tables);

        // Resolve joins for all tables
        $joinPlan = $this->joinResolver->resolve(array_values($this->tables));

        return new QueryPlan(
            primaryTable: $primaryTable,
            tables: $this->tables,
            metrics: $this->metrics,
            joinPlan: $joinPlan,
        );
    }
}
