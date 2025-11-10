<?php

use NickPotts\Slice\Engine\Joins\JoinPlan;
use NickPotts\Slice\Engine\QueryPlan;
use NickPotts\Slice\Tests\Support\MockSliceSource;

it('stores primary table', function () {
    $table = new MockSliceSource('orders');
    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
        joinPlan: new JoinPlan,
    );

    expect($plan->primaryTable)->toBe($table);
});

it('returns primary table name', function () {
    $table = new MockSliceSource('orders');
    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
        joinPlan: new JoinPlan,
    );

    expect($plan->getPrimaryTableName())->toBe('orders');
});

it('returns all table names', function () {
    $orders = new MockSliceSource('orders');
    $customers = new MockSliceSource('customers');

    $plan = new QueryPlan(
        primaryTable: $orders,
        tables: [
            'orders' => $orders,
            'customers' => $customers,
        ],
        metrics: [],
        joinPlan: new JoinPlan,
    );

    expect($plan->getTableNames())->toEqual(['orders', 'customers']);
});

it('returns metrics', function () {
    $table = new MockSliceSource('orders');
    $source = new \NickPotts\Slice\Support\MetricSource($table, 'total');

    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: ['sum_orders_total' => $source],
        joinPlan: new JoinPlan,
    );

    expect($plan->getMetrics())->toBe(['sum_orders_total' => $source]);
});

it('stores join plan', function () {
    $table = new MockSliceSource('orders');
    $joinPlan = new JoinPlan;

    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
        joinPlan: $joinPlan,
    );

    expect($plan->joinPlan)->toBe($joinPlan);
});
