<?php

use NickPotts\Slice\Metrics\Aggregations\Count;
use NickPotts\Slice\Metrics\Aggregations\Sum;
use NickPotts\Slice\Providers\Eloquent\EloquentSchemaProvider;
use Workbench\App\Models\Order;

beforeEach(function () {
    Order::factory()->create(['total' => 100.00]);
    Order::factory()->create(['total' => 200.00]);
    Order::factory()->create(['total' => 150.00]);
});

it('builds query plan from workbench models', function () {
    // Register workbench models
    $manager = app('slice.schema-provider-manager');
    $manager->register(new EloquentSchemaProvider([
        'workbench/app/Models' => 'Workbench\App\Models',
    ]));

    $aggregations = [
        Sum::make('orders.total'),
        Count::make('orders.id'),
    ];

    $normalized = \NickPotts\Slice\Slice::normalizeMetrics($aggregations);
    $plan = \NickPotts\Slice\Slice::query()
        ->addMetrics($normalized)
        ->build();

    expect($plan->primaryTable)->not->toBeNull();
    expect($plan->getPrimaryTableName())->toBe('orders');
    expect($plan->getTableNames())->toContain('orders');
    expect($plan->getMetrics())->toHaveCount(2);
});

it('resolves correct table from provider', function () {
    $manager = app('slice.schema-provider-manager');
    $manager->register(new EloquentSchemaProvider([
        'workbench/app/Models' => 'Workbench\App\Models',
    ]));

    $aggregations = [Sum::make('orders.total')];
    $normalized = \NickPotts\Slice\Slice::normalizeMetrics($aggregations);

    expect($normalized[0]['source']->tableName())->toBe('orders');
    expect($normalized[0]['source']->slice)->not->toBeNull();
});
