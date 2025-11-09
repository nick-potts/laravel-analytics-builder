<?php

use NickPotts\Slice\Metrics\Aggregations\Sum;
use Workbench\App\Models\Order;

beforeEach(function () {
    Order::factory()->create(['total' => 100.00]);
    Order::factory()->create(['total' => 200.00]);
    Order::factory()->create(['total' => 150.00]);
});

it('generates valid SQL for current driver', function () {
    $sum = Sum::make('orders.total');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $sum->toSql($grammar);

    expect($sql)->toContain('SUM');
    expect($sql)->toContain('orders');
    expect($sql)->toContain('total');
    expect($sql)->toContain('AS');
});

it('executes SUM aggregation correctly', function () {
    $sum = Sum::make('orders.total');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $sum->toSql($grammar);

    // Build raw SQL with the aggregation
    $fullSql = 'SELECT ' . $sql . ' FROM orders';
    $result = DB::connection('testing')->selectOne($fullSql);

    // Different drivers return different numeric formats (450, 450.0, 450.00), so cast to float
    expect((float) $result->sum_orders_total)->toEqual(450.0);
});

it('supports custom alias in SQL', function () {
    $sum = Sum::make('orders.total')->setAlias('total_revenue');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $sum->toSql($grammar);

    expect($sql)->toContain('total_revenue');
});
