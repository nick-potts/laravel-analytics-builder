<?php

use NickPotts\Slice\Engine\QueryPlan;
use NickPotts\Slice\Tests\Support\MockTableContract;

it('stores primary table', function () {
    $table = new MockTableContract('orders');
    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
    );

    expect($plan->primaryTable)->toBe($table);
});

it('returns primary table name', function () {
    $table = new MockTableContract('orders');
    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
    );

    expect($plan->getPrimaryTableName())->toBe('orders');
});

it('returns all table names', function () {
    $orders = new MockTableContract('orders');
    $customers = new MockTableContract('customers');

    $plan = new QueryPlan(
        primaryTable: $orders,
        tables: [
            'orders' => $orders,
            'customers' => $customers,
        ],
        metrics: [],
    );

    expect($plan->getTableNames())->toBe(['orders', 'customers']);
});

it('returns metrics', function () {
    $table = new MockTableContract('orders');
    $source = new \NickPotts\Slice\Support\MetricSource($table, 'total');

    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: ['sum_orders_total' => $source],
    );

    expect($plan->getMetrics())->toBe(['sum_orders_total' => $source]);
});
