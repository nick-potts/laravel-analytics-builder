<?php

use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;
use NickPotts\Slice\Slice;

test('can execute simple single-table query with metrics', function () {
    // Create test data
    DB::table('orders')->insert([
        ['customer_id' => 1, 'total' => 100.00, 'created_at' => '2024-01-01'],
        ['customer_id' => 1, 'total' => 150.00, 'created_at' => '2024-01-02'],
        ['customer_id' => 2, 'total' => 200.00, 'created_at' => '2024-01-01'],
    ]);

    // Execute query
    $results = Slice::query()
        ->metrics([
            Sum::make('orders.total'),
            Count::make('orders.id'),
        ])
        ->get();

    expect($results)->toBeArray()
        ->and($results)->toHaveCount(1)
        ->and($results[0])->toHaveKey('orders_total')
        ->and($results[0])->toHaveKey('orders_id')
        ->and($results[0]['orders_total'])->toBe(450.0)
        ->and($results[0]['orders_id'])->toBe(3);
});

test('can execute query with time dimension', function () {
    // Create test data
    DB::table('orders')->insert([
        ['customer_id' => 1, 'total' => 100.00, 'created_at' => '2024-01-01 10:00:00'],
        ['customer_id' => 1, 'total' => 150.00, 'created_at' => '2024-01-01 14:00:00'],
        ['customer_id' => 2, 'total' => 200.00, 'created_at' => '2024-01-02 10:00:00'],
    ]);

    // Execute query with daily dimension
    $results = Slice::query()
        ->metrics([Sum::make('orders.total')])
        ->dimensions([TimeDimension::make('created_at')->daily()])
        ->get();

    expect($results)->toBeArray()
        ->and($results)->toHaveCount(2)
        ->and($results[0])->toHaveKey('orders_created_at_day')
        ->and($results[0])->toHaveKey('orders_total')
        ->and($results[0]['orders_total'])->toBe(250.0) // Jan 1st
        ->and($results[1]['orders_total'])->toBe(200.0); // Jan 2nd
});
