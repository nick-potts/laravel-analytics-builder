<?php

use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Facades\Slice;
use NickPotts\Slice\Metrics\Avg;
use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Support\Registry;
use NickPotts\Slice\Tests\Support\Drivers\NoJoinLaravelDriver;
use Workbench\App\Analytics\OrderItems\OrderItemsTable;
use Workbench\App\Analytics\Orders\OrdersTable;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Product;

beforeEach(function () {
    // Run migrations
    $this->artisan('migrate', ['--database' => 'testing']);

    // Register the orders table
    app(Registry::class)->registerTable(new OrdersTable);

    // Create test orders using factories
    Order::factory()->withTotal(100.00)->create();
    Order::factory()->withTotal(200.00)->create();
    Order::factory()->withTotal(50.00)->create();
});

test('can query sum of orders total', function () {
    $metric = Sum::make('orders.total')->currency('USD');

    $results = Slice::query()
        ->metrics([$metric])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()[$metric->key()])->toBe(350);
});

test('can query multiple metrics at once', function () {
    $results = Slice::query()
        ->metrics([
            Sum::make('orders.total')->label('Revenue'),
            Sum::make('orders.shipping')->label('Shipping'),
            Count::make('orders.id')->label('Order Count'),
        ])
        ->get();

    $result = $results->first();

    expect($results)->toHaveCount(1)
        ->and($result['orders_total'])->toBe(350)
        ->and($result)->toHaveKey('orders_shipping')
        ->and($result['orders_id'])->toBe(3);
});

test('can query average', function () {
    $results = Slice::query()
        ->metrics([
            Avg::make('orders.total')->label('Average Order Value'),
        ])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()['orders_total'])->toBeGreaterThan(0);
});

test('can mix direct aggregations with enum metrics', function () {
    $results = Slice::query()
        ->metrics([
            \Workbench\App\Analytics\Orders\OrdersMetric::Revenue,
            Sum::make('orders.shipping')->label('Shipping'),
            Count::make('orders.id'),
        ])
        ->get();

    $result = $results->first();

    expect($results)->toHaveCount(1)
        ->and($result)->toHaveKey('orders_total') // Revenue enum returns Sum::make('orders.total')
        ->and($result)->toHaveKey('orders_shipping')
        ->and($result)->toHaveKey('orders_id');
});

test('software-join fallback matches database join output', function () {
    $registry = app(Registry::class);
    $registry->registerTable(new OrderItemsTable);

    $customer = Customer::factory()->create();

    $productA = Product::create([
        'sku' => 'SKU-A',
        'name' => 'Widget A',
        'price' => 50,
    ]);

    $productB = Product::create([
        'sku' => 'SKU-B',
        'name' => 'Widget B',
        'price' => 75,
    ]);

    $orderOne = Order::factory()
        ->for($customer)
        ->withTotal(150)
        ->state(['status' => 'completed'])
        ->create();

    $orderTwo = Order::factory()
        ->for($customer)
        ->withTotal(200)
        ->state(['status' => 'processing'])
        ->create();

    OrderItem::create([
        'order_id' => $orderOne->id,
        'product_id' => $productA->id,
        'quantity' => 2,
        'price' => 60,
        'cost' => 30,
    ]);

    OrderItem::create([
        'order_id' => $orderTwo->id,
        'product_id' => $productB->id,
        'quantity' => 1,
        'price' => 80,
        'cost' => 40,
    ]);

    $metricsFactory = fn () => [
        Sum::make('orders.total')->label('Revenue'),
        Sum::make('order_items.price')->label('Item Revenue'),
    ];

    $dimensionFactory = fn () => Dimension::make('status')->label('Order Status');

    $expected = Slice::query()
        ->metrics($metricsFactory())
        ->dimensions([$dimensionFactory()])
        ->get()
        ->toArray();

    app()->singleton(QueryDriver::class, fn () => new NoJoinLaravelDriver('testing'));
    app()->forgetInstance(QueryDriver::class);
    app()->forgetInstance(\NickPotts\Slice\Slice::class);
    \NickPotts\Slice\Facades\Slice::clearResolvedInstance(\NickPotts\Slice\Slice::class);

    $software = Slice::query()
        ->metrics($metricsFactory())
        ->dimensions([$dimensionFactory()])
        ->get()
        ->toArray();

    expect($software)->toEqual($expected);
});
