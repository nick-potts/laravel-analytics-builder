<?php

use NickPotts\Slice\Engine\QueryBuilder;
use NickPotts\Slice\Engine\QueryPlan;
use NickPotts\Slice\Support\SchemaProviderManager;
use NickPotts\Slice\Tests\Support\MockSchemaProvider;
use NickPotts\Slice\Tests\Support\MockTableContract;

it('adds normalized metrics', function () {
    $manager = new SchemaProviderManager;
    $builder = new QueryBuilder($manager);

    $table = new MockTableContract('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    $manager->register($provider);

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
    $manager = new SchemaProviderManager;
    $builder = new QueryBuilder($manager);

    $table = new MockTableContract('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    $manager->register($provider);

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
    $manager = new SchemaProviderManager;
    $builder = new QueryBuilder($manager);

    expect(fn () => $builder->build())
        ->toThrow(\RuntimeException::class);
});
