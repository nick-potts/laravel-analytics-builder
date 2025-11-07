<?php

use NickPotts\Slice\Metrics\Avg;
use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Metrics\Sum;

test('Sum aggregation can be created with table.column format', function () {
    $sum = Sum::make('orders.total');

    expect($sum->key())->toBe('orders_total')
        ->and($sum->tableName())->toBe('orders')
        ->and($sum->aggregationType())->toBe('sum');
});

test('Sum supports fluent currency formatting', function () {
    $sum = Sum::make('orders.total')
        ->currency('USD')
        ->label('Revenue');

    expect($sum->getCurrency())->toBe('USD')
        ->and($sum->getLabel())->toBe('Revenue');
});

test('Sum supports fluent decimals formatting', function () {
    $sum = Sum::make('orders.total')
        ->decimals(3);

    expect($sum->getDecimals())->toBe(3);
});

test('Sum supports fluent percentage formatting', function () {
    $sum = Sum::make('orders.conversion_rate')
        ->percentage();

    expect($sum->isPercentage())->toBeTrue();
});

test('Count aggregation defaults to 0 decimals', function () {
    $count = Count::make('orders.id');

    expect($count->getDecimals())->toBe(0)
        ->and($count->aggregationType())->toBe('count');
});

test('Avg aggregation defaults to 2 decimals', function () {
    $avg = Avg::make('orders.total');

    expect($avg->getDecimals())->toBe(2)
        ->and($avg->aggregationType())->toBe('avg');
});

test('aggregations can use closures for dynamic configuration', function () {
    $sum = Sum::make('orders.total')
        ->label(fn () => 'Dynamic Label')
        ->currency(fn () => 'EUR');

    expect($sum->getLabel())->toBe('Dynamic Label')
        ->and($sum->getCurrency())->toBe('EUR');
});

test('aggregations auto-generate labels from column names', function () {
    $sum = Sum::make('orders.total_revenue');

    expect($sum->getLabel())->toBe('Total Revenue');
});

test('aggregations support when() conditional', function () {
    $sum = Sum::make('orders.total')
        ->when(true, fn ($metric) => $metric->currency('USD'))
        ->when(false, fn ($metric) => $metric->currency('EUR'));

    expect($sum->getCurrency())->toBe('USD');
});

test('aggregations support unless() conditional', function () {
    $sum = Sum::make('orders.total')
        ->unless(false, fn ($metric) => $metric->currency('USD'))
        ->unless(true, fn ($metric) => $metric->currency('EUR'));

    expect($sum->getCurrency())->toBe('USD');
});

test('aggregations can be extended via macros', function () {
    Sum::macro('bitcoin', function () {
        return $this->decimals(8)->label('BTC');
    });

    $sum = Sum::make('orders.total')->bitcoin();

    expect($sum->getDecimals())->toBe(8)
        ->and($sum->getLabel())->toBe('BTC');
});

test('toArray includes all formatting information', function () {
    $sum = Sum::make('orders.total')
        ->label('Revenue')
        ->currency('USD')
        ->decimals(2);

    $array = $sum->toArray();

    expect($array)->toHaveKey('name', 'total')
        ->and($array)->toHaveKey('label', 'Revenue')
        ->and($array)->toHaveKey('formatter', 'currency')
        ->and($array)->toHaveKey('column_type', 'money')
        ->and($array['format_options'])->toBe([
            'currency' => 'USD',
            'precision' => 2,
        ]);
});
