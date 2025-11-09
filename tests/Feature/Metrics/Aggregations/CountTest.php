<?php

use NickPotts\Slice\Metrics\Aggregations\Count;
use Workbench\App\Models\Order;

beforeEach(function () {
    Order::factory()->create();
    Order::factory()->create();
    Order::factory()->create();
});

it('generates valid SQL for current driver', function () {
    $count = Count::make('orders.id');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $count->toSql($grammar);

    expect($sql)->toContain('COUNT');
    expect($sql)->toContain('orders');
    expect($sql)->toContain('id');
    expect($sql)->toContain('AS');
});

it('executes COUNT aggregation correctly', function () {
    $count = Count::make('orders.id');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $count->toSql($grammar);

    $fullSql = 'SELECT ' . $sql . ' FROM orders';
    $result = DB::connection('testing')->selectOne($fullSql);

    expect((int) $result->count_orders_id)->toEqual(3);
});

it('supports custom alias in SQL', function () {
    $count = Count::make('orders.id')->setAlias('total_orders');
    $grammar = DB::connection('testing')->getQueryGrammar();

    $sql = $count->toSql($grammar);

    expect($sql)->toContain('total_orders');
});
