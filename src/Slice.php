<?php

namespace NickPotts\Slice;

use NickPotts\Slice\Contracts\AggregationMetric;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;
use NickPotts\Slice\Engine\QueryBuilder;
use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Main entry point for Slice analytics queries.
 *
 * Usage:
 * Slice::query()
 *     ->metrics([Sum::make('orders.total')])
 *     ->dimensions([TimeDimension::make('orders.created_at')->daily()])
 *     ->get();
 */
class Slice
{
    protected SchemaProviderManager $providerManager;

    protected QueryDriver $driver;

    protected QueryBuilder $builder;

    /** @var array<AggregationMetric> */
    protected array $metrics = [];

    /** @var array<Dimension> */
    protected array $dimensions = [];

    public function __construct(
        ?SchemaProviderManager $providerManager = null,
        ?QueryDriver $driver = null
    ) {
        // Use service container if available
        if (function_exists('app')) {
            $this->providerManager = $providerManager ?? app(SchemaProviderManager::class);
            $this->driver = $driver ?? app(QueryDriver::class);
        } else {
            $this->providerManager = $providerManager ?? new SchemaProviderManager;
            $this->driver = $driver ?? new LaravelQueryDriver;
        }

        $this->builder = new QueryBuilder($this->providerManager, $this->driver);
    }

    /**
     * Start a new query.
     */
    public static function query(): static
    {
        return new static;
    }

    /**
     * Add metrics to the query.
     *
     * @param  array<AggregationMetric>  $metrics
     */
    public function metrics(array $metrics): static
    {
        $this->metrics = $metrics;

        return $this;
    }

    /**
     * Add dimensions (GROUP BY) to the query.
     *
     * @param  array<Dimension>  $dimensions
     */
    public function dimensions(array $dimensions): static
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    /**
     * Execute the query and get results.
     */
    public function get(): array
    {
        return $this->builder->build($this->metrics, $this->dimensions);
    }
}
