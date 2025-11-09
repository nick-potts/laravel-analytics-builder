<?php

use NickPotts\Slice\Engine\Joins\JoinPlan;
use NickPotts\Slice\Engine\QueryPlan;
use NickPotts\Slice\Tests\Support\MockTableContract;

it('stores primary table', function () {
    $table = new MockTableContract('orders');
    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
        joinPlan: new JoinPlan(),
    );

    expect($plan->primaryTable)->toBe($table);
});

it('returns primary table name', function () {
    $table = new MockTableContract('orders');
    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
        joinPlan: new JoinPlan(),
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
        joinPlan: new JoinPlan(),
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
        joinPlan: new JoinPlan(),
    );

    expect($plan->getMetrics())->toBe(['sum_orders_total' => $source]);
});

it('stores join plan', function () {
    $table = new MockTableContract('orders');
    $joinPlan = new JoinPlan();

    $plan = new QueryPlan(
        primaryTable: $table,
        tables: ['orders' => $table],
        metrics: [],
        joinPlan: $joinPlan,
    );

    expect($plan->joinPlan)->toBe($joinPlan);
});
