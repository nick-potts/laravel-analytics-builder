<?php

use NickPotts\Slice\Engine\QueryBuilder;
use NickPotts\Slice\Engine\QueryPlan;
use NickPotts\Slice\Tests\Support\MockSchemaProvider;
use NickPotts\Slice\Tests\Support\MockSliceSource;

it('adds normalized metrics', function () {
    $builder = app(QueryBuilder::class);

    $table = new MockSliceSource('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    app('slice.schema-provider-manager')->register($provider);

    $normalized = [
        [
            'source' => new \NickPotts\Slice\Support\MetricSource($table, 'total'),
            'aggregation' => new \NickPotts\Slice\Metrics\Aggregations\Sum('orders.total'),
        ],
    ];

    $result = $builder->addMetrics($normalized);

    expect($result)->toBe($builder);
});

it('builds query plan', function () {
    $builder = app(QueryBuilder::class);

    $table = new MockSliceSource('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    app('slice.schema-provider-manager')->register($provider);

    $normalized = [
        [
            'source' => new \NickPotts\Slice\Support\MetricSource($table, 'total'),
            'aggregation' => new \NickPotts\Slice\Metrics\Aggregations\Sum('orders.total'),
        ],
    ];

    $plan = $builder->addMetrics($normalized)->build();

    expect($plan)->toBeInstanceOf(QueryPlan::class);
    expect($plan->getPrimaryTableName())->toBe('orders');
});

it('throws on no metrics', function () {
    $builder = app(QueryBuilder::class);

    expect(fn () => $builder->build())
        ->toThrow(\RuntimeException::class);
});

it('resolves joins for single table', function () {
    $builder = app(QueryBuilder::class);

    $table = new MockSliceSource('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    app('slice.schema-provider-manager')->register($provider);

    $normalized = [
        [
            'source' => new \NickPotts\Slice\Support\MetricSource($table, 'total'),
            'aggregation' => new \NickPotts\Slice\Metrics\Aggregations\Sum('orders.total'),
        ],
    ];

    $plan = $builder->addMetrics($normalized)->build();

    expect($plan->joinPlan->isEmpty())->toBeTrue();
});

it('returns join plan from resolver', function () {
    $builder = app(QueryBuilder::class);

    $table = new MockSliceSource('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    app('slice.schema-provider-manager')->register($provider);

    $normalized = [
        [
            'source' => new \NickPotts\Slice\Support\MetricSource($table, 'total'),
            'aggregation' => new \NickPotts\Slice\Metrics\Aggregations\Sum('orders.total'),
        ],
    ];

    $plan = $builder->addMetrics($normalized)->build();

    expect($plan->joinPlan)->not->toBeNull();
});
