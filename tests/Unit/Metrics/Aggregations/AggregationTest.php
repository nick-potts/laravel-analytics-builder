<?php

use NickPotts\Slice\Metrics\Aggregations\Sum;
use NickPotts\Slice\Metrics\Aggregations\Count;
use NickPotts\Slice\Metrics\Aggregations\Avg;

it('creates aggregation with make factory', function () {
    $sum = Sum::make('orders.total');

    expect($sum)->toBeInstanceOf(Sum::class);
    expect($sum->getReference())->toBe('orders.total');
});

it('generates default alias from reference', function () {
    $count = Count::make('orders.id');

    expect($count->getAlias())->toBe('count_orders_id');
});

it('supports custom alias', function () {
    $avg = Avg::make('products.price')
        ->setAlias('average_price');

    expect($avg->getAlias())->toBe('average_price');
});

it('returns custom alias when set', function () {
    $sum = Sum::make('orders.total')->setAlias('revenue');

    expect($sum->getAlias())->toBe('revenue');
});
