<?php

use Illuminate\Support\Facades\DB;
use NickPotts\Slice\Contracts\Metric;
use NickPotts\Slice\Contracts\MetricContract;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Slice;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Models\Order;

beforeEach(function () {
    // Create test data
    Order::create(['total' => 1000, 'item_cost' => 400, 'shipping_cost' => 100, 'country' => 'US', 'created_at' => '2024-01-01']);
    Order::create(['total' => 2000, 'item_cost' => 800, 'shipping_cost' => 150, 'country' => 'US', 'created_at' => '2024-01-01']);
    Order::create(['total' => 1500, 'item_cost' => 600, 'shipping_cost' => 120, 'country' => 'CA', 'created_at' => '2024-01-02']);
});

it('executes single-level database CTE for computed metrics', function () {
    // Define test metric enum
    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('total')->label('Revenue'),
        ],
        [
            'key' => 'orders_item_cost',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('item_cost')->label('Item Cost'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_item_cost')
                ->dependsOn('orders_revenue', 'orders_item_cost')
                ->label('Gross Profit')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results[0]['orders_revenue'])->toBe(4500)
        ->and($results[0]['orders_item_cost'])->toBe(1800)
        ->and($results[0]['orders_gross_profit'])->toBe(2700);
});

it('executes multi-level database CTE with nested dependencies', function () {
    $metrics = [
        // Level 0
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'orders_item_cost',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('item_cost'),
        ],
        [
            'key' => 'orders_shipping_cost',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('shipping_cost'),
        ],
        // Level 1
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_item_cost')
                ->dependsOn('orders_revenue', 'orders_item_cost')
                ->forTable(new TestOrdersTable),
        ],
        [
            'key' => 'orders_net_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_item_cost - orders_shipping_cost')
                ->dependsOn('orders_revenue', 'orders_item_cost', 'orders_shipping_cost')
                ->forTable(new TestOrdersTable),
        ],
        // Level 2
        [
            'key' => 'orders_gross_margin',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('(orders_gross_profit / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_gross_profit', 'orders_revenue')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results[0]['orders_gross_profit'])->toBe(2700)
        ->and($results[0]['orders_net_profit'])->toBe(2330)
        ->and($results[0]['orders_gross_margin'])->toBe(60.0);
});

it('executes database CTE with dimensions', function () {
    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'orders_item_cost',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('item_cost'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_item_cost')
                ->dependsOn('orders_revenue', 'orders_item_cost')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->dimensions([
            TimeDimension::make('created_at')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0]['orders_gross_profit'])->toBe(1800) // 2024-01-01
        ->and($results[1]['orders_gross_profit'])->toBe(900); // 2024-01-02
});

it('handles NULLIF in database CTE to prevent division by zero', function () {
    // Add order with zero revenue
    Order::create(['total' => 0, 'item_cost' => 0, 'shipping_cost' => 0, 'country' => 'UK', 'created_at' => '2024-01-03']);

    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'orders_item_cost',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('item_cost'),
        ],
        [
            'key' => 'orders_margin',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('((orders_revenue - orders_item_cost) / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_revenue', 'orders_item_cost')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->dimensions([
            TimeDimension::make('created_at')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(3)
        ->and($results[0]['orders_margin'])->toBe(60.0) // 2024-01-01
        ->and($results[1]['orders_margin'])->toBe(60.0) // 2024-01-02
        ->and($results[2]['orders_margin'])->toBeNull(); // 2024-01-03 (division by zero)
});

it('generates valid SQL with CTE syntax', function () {
    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue * 0.6')
                ->dependsOn('orders_revenue')
                ->forTable(new TestOrdersTable),
        ],
    ];

    // Enable query logging
    DB::enableQueryLog();

    Slice::query()
        ->metricsRaw($metrics)
        ->get();

    $queries = DB::getQueryLog();

    // Check that the query uses WITH clause
    expect($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('with');
});

// Test helper table class
class TestOrdersTable extends Table
{
    protected string $table = 'orders';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('created_at')
                ->asTimestamp()
                ->minGranularity('day'),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
