<?php

use NickPotts\Slice\Engine\QueryBuilder;
use NickPotts\Slice\Metrics\Aggregations\Sum;
use NickPotts\Slice\SliceManager;
use NickPotts\Slice\Support\SchemaProviderManager;
use NickPotts\Slice\Tests\Support\MockSchemaProvider;
use NickPotts\Slice\Tests\Support\MockSliceSource;

it('returns query builder', function () {
    $manager = new SchemaProviderManager;
    $slice = new SliceManager($manager);

    $builder = $slice->query();

    expect($builder)->toBeInstanceOf(QueryBuilder::class);
});

it('normalizes metrics to sources', function () {
    $manager = new SchemaProviderManager;
    $table = new MockSliceSource('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('test');
    $manager->register($provider);

    $slice = new SliceManager($manager);

    $aggregations = [
        Sum::make('orders.total'),
    ];

    $normalized = $slice->normalizeMetrics($aggregations);

    expect($normalized)->toHaveCount(1);
    expect($normalized[0])->toHaveKeys(['source', 'aggregation']);
    expect($normalized[0]['source']->tableName())->toBe('orders');
});

it('returns schema provider manager', function () {
    $manager = new SchemaProviderManager;
    $slice = new SliceManager($manager);

    expect($slice->getManager())->toBe($manager);
});
