<?php

namespace NickPotts\Slice;

use NickPotts\Slice\Contracts\Metric;
use NickPotts\Slice\Contracts\MetricContract as MetricEnum;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\DimensionResolver;
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;
use NickPotts\Slice\Engine\Plans\SoftwareJoinQueryPlan;
use NickPotts\Slice\Engine\PostProcessor;
use NickPotts\Slice\Engine\QueryBuilder;
use NickPotts\Slice\Engine\QueryExecutor;
use NickPotts\Slice\Engine\ResultCollection;
use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Support\Registry;
use NickPotts\Slice\Tables\Table;

class Slice
{
    protected QueryBuilder $builder;

    protected QueryExecutor $executor;

    protected PostProcessor $postProcessor;

    protected DimensionResolver $dimensionResolver;

    protected Registry $registry;

    protected array $selectedMetrics = [];

    protected array $selectedDimensions = [];

    protected QueryDriver $driver;

    public function __construct(
        ?QueryBuilder $builder = null,
        ?QueryExecutor $executor = null,
        ?PostProcessor $postProcessor = null,
        ?DimensionResolver $dimensionResolver = null,
        ?Registry $registry = null,
        ?QueryDriver $driver = null,
    ) {
        if (! $driver) {
            $driver = function_exists('app') && app()->bound(QueryDriver::class)
                ? app(QueryDriver::class)
                : new LaravelQueryDriver;
        }

        $this->driver = $driver;
        $this->builder = $builder ?? new QueryBuilder(null, null, null, $this->driver);
        $this->executor = $executor ?? new QueryExecutor;
        $this->postProcessor = $postProcessor ?? new PostProcessor;
        $this->dimensionResolver = $dimensionResolver ?? new DimensionResolver;
        $this->registry = $registry ?? app(Registry::class);
    }

    public function query(?callable $callback = null): static
    {
        if ($callback) {
            $callback($this->builder);
        }

        return $this;
    }

    /**
     * Select metrics to query.
     *
     * @param  array<MetricEnum|string>  $metrics  Array of metric enums or string keys
     */
    public function metrics(array $metrics): static
    {
        $this->selectedMetrics = $metrics;

        return $this;
    }

    /**
     * Select dimensions to group by.
     *
     * @param  array<Dimension>  $dimensions  Array of dimension instances
     */
    public function dimensions(array $dimensions): static
    {
        $this->selectedDimensions = $dimensions;

        return $this;
    }

    public function get(): ResultCollection
    {
        // Normalize metrics to unified format
        $normalizedMetrics = $this->normalizeMetrics($this->selectedMetrics);

        // Build and execute query
        $plan = $this->builder->build($normalizedMetrics, $this->selectedDimensions);
        $rows = $this->executor->run($plan);

        $forceSoftwareComputed = $plan instanceof SoftwareJoinQueryPlan;

        return $this->postProcessor->process($rows, $normalizedMetrics, $forceSoftwareComputed);
    }

    /**
     * Normalize metric inputs to a consistent format.
     *
     * @param  array<MetricEnum|Metric|string>  $metrics
     * @return array<array{enum: ?MetricEnum, table: ?Table, metric: \NickPotts\Slice\Contracts\Metric, key: string}>
     */
    protected function normalizeMetrics(array $metrics): array
    {
        $normalized = [];

        foreach ($metrics as $metric) {
            if ($metric instanceof Metric) {
                // Handle direct metric instances (e.g., Sum::make('orders.total'))
                // These have their table resolved internally
                $table = method_exists($metric, 'table') ? $metric->table() : null;
                $key = $metric->key();

                $normalized[] = [
                    'enum' => null,
                    'table' => $table,
                    'metric' => $metric,
                    'key' => $key,
                ];
            } elseif ($metric instanceof MetricEnum) {
                // Handle enum cases (e.g., OrdersMetric::Revenue)
                // Enums can return either aggregations or computed metrics
                $table = $metric->table();
                $metricDefinition = $metric->get();
                $key = $metricDefinition->key(); // Metric key already includes table_column format

                $normalized[] = [
                    'enum' => $metric,
                    'table' => $table,
                    'metric' => $metricDefinition,
                    'key' => $key,
                ];
            } elseif (is_string($metric)) {
                // Support string-based metric keys via registry lookup
                $metricEnum = $this->registry->lookupMetric($metric);

                if (! $metricEnum) {
                    throw new \InvalidArgumentException("Metric '{$metric}' not found in registry. Available metrics: ".implode(', ', array_keys($this->registry->metrics())));
                }

                $table = $metricEnum->table();
                $metricDefinition = $metricEnum->get();
                $key = $metricDefinition->key(); // Metric key already includes table_column format

                $normalized[] = [
                    'enum' => $metricEnum,
                    'table' => $table,
                    'metric' => $metricDefinition,
                    'key' => $key,
                ];
            } else {
                throw new \InvalidArgumentException('Metrics must be instances of Metric, MetricContract (enums), or strings.');
            }
        }

        return $normalized;
    }
}
