<?php

use Illuminate\Support\Facades\DB;
use NickPotts\Slice\Facades\Slice;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

beforeEach(function () {
    // Create test customer
    $customer = Customer::create(['name' => 'Test Customer', 'email' => 'test@example.com', 'segment' => 'general']);

    // Create test data
    Order::create(['customer_id' => $customer->id, 'total' => 1000, 'subtotal' => 400, 'shipping' => 100, 'tax' => 50, 'status' => 'completed', 'created_at' => '2024-01-01']);
    Order::create(['customer_id' => $customer->id, 'total' => 2000, 'subtotal' => 800, 'shipping' => 150, 'tax' => 100, 'status' => 'completed', 'created_at' => '2024-01-01']);
    Order::create(['customer_id' => $customer->id, 'total' => 1500, 'subtotal' => 600, 'shipping' => 120, 'tax' => 80, 'status' => 'completed', 'created_at' => '2024-01-02']);
});

it('executes single-level database CTE for computed metrics', function () {
    // Define test metric enum
    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.total')->label('Revenue'),
        ],
        [
            'key' => 'orders_subtotal',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.subtotal')->label('Subtotal'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_subtotal')
                ->dependsOn('orders_revenue', 'orders_subtotal')
                ->label('Gross Profit')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metrics($metrics)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results[0]['orders_revenue'])->toBe(4500)
        ->and($results[0]['orders_subtotal'])->toBe(1800)
        ->and($results[0]['orders_gross_profit'])->toBe(2700);
});

it('executes multi-level database CTE with nested dependencies', function () {
    $metrics = [
        // Level 0
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'orders_subtotal',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.subtotal'),
        ],
        [
            'key' => 'orders_shipping',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.shipping'),
        ],
        // Level 1
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_subtotal')
                ->dependsOn('orders_revenue', 'orders_subtotal')
                ->forTable(new TestOrdersTable),
        ],
        [
            'key' => 'orders_net_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_subtotal - orders_shipping')
                ->dependsOn('orders_revenue', 'orders_subtotal', 'orders_shipping')
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
        ->metrics($metrics)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results[0]['orders_gross_profit'])->toBe(2700)
        ->and($results[0]['orders_net_profit'])->toBe(2330)
        ->and($results[0]['orders_gross_margin'])->toBeCloseTo(60.0, 0.1);
});

it('executes database CTE with dimensions', function () {
    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'orders_subtotal',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.subtotal'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_subtotal')
                ->dependsOn('orders_revenue', 'orders_subtotal')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metrics($metrics)
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
    $customer = Customer::first();
    Order::create(['customer_id' => $customer->id, 'total' => 0, 'subtotal' => 0, 'shipping' => 0, 'tax' => 0, 'status' => 'completed', 'created_at' => '2024-01-03']);

    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'orders_subtotal',
            'table' => new TestOrdersTable,
            'metric' => Sum::make('orders.subtotal'),
        ],
        [
            'key' => 'orders_margin',
            'table' => new TestOrdersTable,
            'metric' => Computed::make('((orders_revenue - orders_subtotal) / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_revenue', 'orders_subtotal')
                ->forTable(new TestOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metrics($metrics)
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
            'metric' => Sum::make('orders.total'),
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
        ->metrics($metrics)
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
