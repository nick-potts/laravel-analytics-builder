<?php

use NickPotts\Slice\Metrics\Aggregations\Avg;
use Workbench\App\Models\Order;

beforeEach(function () {
    Order::factory()->create(['total' => 100.00]);
    Order::factory()->create(['total' => 200.00]);
    Order::factory()->create(['total' => 300.00]);
});

it('generates valid SQL for current driver', function () {
    $avg = Avg::make('orders.total');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $avg->toSql($grammar);

    expect($sql)->toContain('AVG');
    expect($sql)->toContain('orders');
    expect($sql)->toContain('total');
    expect($sql)->toContain('AS');
});

it('executes AVG aggregation correctly', function () {
    $avg = Avg::make('orders.total');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $avg->toSql($grammar);

    $fullSql = 'SELECT ' . $sql . ' FROM orders';
    $result = DB::connection('testing')->selectOne($fullSql);

    // Average of 100, 200, 300 = 200
    expect((float) $result->avg_orders_total)->toEqual(200.0);
});

it('supports custom alias in SQL', function () {
    $avg = Avg::make('orders.total')->setAlias('average_value');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $avg->toSql($grammar);

    expect($sql)->toContain('average_value');
});
