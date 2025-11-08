<?php

use Illuminate\Support\Facades\DB;
use NickPotts\Slice\Facades\Slice;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Support\Registry;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

beforeEach(function () {
    // Register test table
    app(Registry::class)->registerTable(new TestOrdersTable);

    // Create test customer
    $customer = Customer::create(['name' => 'Test Customer', 'email' => 'test@example.com', 'segment' => 'general']);

    // Create test data
    Order::create(['customer_id' => $customer->id, 'total' => 1000, 'subtotal' => 400, 'shipping' => 100, 'tax' => 50, 'status' => 'completed', 'created_at' => '2024-01-01']);
    Order::create(['customer_id' => $customer->id, 'total' => 2000, 'subtotal' => 800, 'shipping' => 150, 'tax' => 100, 'status' => 'completed', 'created_at' => '2024-01-01']);
    Order::create(['customer_id' => $customer->id, 'total' => 1500, 'subtotal' => 600, 'shipping' => 120, 'tax' => 80, 'status' => 'completed', 'created_at' => '2024-01-02']);
});

it('executes single-level database CTE for computed metrics', function () {
    $revenue = Sum::make('orders.total')->label('Revenue');
    $subtotal = Sum::make('orders.subtotal')->label('Subtotal');
    $computed = Computed::make('orders_total - orders_subtotal')
        ->dependsOn($revenue, $subtotal)
        ->label('Gross Profit')
        ->forTable(new TestOrdersTable);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $subtotal,
            $computed,
        ])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results[0]['orders_total'])->toBe(4500)
        ->and($results[0]['orders_subtotal'])->toBe(1800)
        ->and($results[0][$computed->key()])->toBe(2700);
});

it('executes multi-level database CTE with nested dependencies', function () {
    $table = new TestOrdersTable;

    // Level 0
    $revenue = Sum::make('orders.total');
    $subtotal = Sum::make('orders.subtotal');
    $shipping = Sum::make('orders.shipping');

    // Level 1
    $grossProfit = Computed::make('orders_total - orders_subtotal')
        ->dependsOn($revenue, $subtotal)
        ->forTable($table);

    $netProfit = Computed::make('orders_total - orders_subtotal - orders_shipping')
        ->dependsOn($revenue, $subtotal, $shipping)
        ->forTable($table);

    // Level 2 - Use the computed key from level 1
    $grossMargin = Computed::make('('.$grossProfit->key().' / NULLIF(orders_total, 0)) * 100')
        ->dependsOn($grossProfit, $revenue)
        ->forTable($table);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $subtotal,
            $shipping,
            $grossProfit,
            $netProfit,
            $grossMargin,
        ])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results[0][$grossProfit->key()])->toBe(2700)
        ->and($results[0][$netProfit->key()])->toBe(2330)
        ->and($results[0][$grossMargin->key()])->toBeGreaterThan(59.9)
        ->and($results[0][$grossMargin->key()])->toBeLessThan(60.1);
});

it('executes database CTE with dimensions', function () {
    $table = new TestOrdersTable;

    $revenue = Sum::make('orders.total');
    $subtotal = Sum::make('orders.subtotal');
    $grossProfit = Computed::make('orders_total - orders_subtotal')
        ->dependsOn($revenue, $subtotal)
        ->forTable($table);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $subtotal,
            $grossProfit,
        ])
        ->dimensions([
            TimeDimension::make('created_at')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0][$grossProfit->key()])->toBe(1800) // 2024-01-01
        ->and($results[1][$grossProfit->key()])->toBe(900); // 2024-01-02
});

it('handles NULLIF in database CTE to prevent division by zero', function () {
    // Add order with zero revenue
    $customer = Customer::first();
    Order::create(['customer_id' => $customer->id, 'total' => 0, 'subtotal' => 0, 'shipping' => 0, 'tax' => 0, 'status' => 'completed', 'created_at' => '2024-01-03']);

    $table = new TestOrdersTable;

    $revenue = Sum::make('orders.total');
    $subtotal = Sum::make('orders.subtotal');
    $margin = Computed::make('((orders_total - orders_subtotal) / NULLIF(orders_total, 0)) * 100')
        ->dependsOn($revenue, $subtotal)
        ->forTable($table);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $subtotal,
            $margin,
        ])
        ->dimensions([
            TimeDimension::make('created_at')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(3)
        ->and($results[0][$margin->key()])->toBe(60.0) // 2024-01-01
        ->and($results[1][$margin->key()])->toBe(60.0) // 2024-01-02
        ->and($results[2][$margin->key()])->toBeNull(); // 2024-01-03 (division by zero)
});

it('generates valid SQL with CTE syntax', function () {
    $table = new TestOrdersTable;

    $revenue = Sum::make('orders.total');
    $computed = Computed::make('orders_total * 0.6')
        ->dependsOn($revenue)
        ->forTable($table);

    // Enable query logging
    DB::enableQueryLog();

    Slice::query()
        ->metrics([
            $revenue,
            $computed,
        ])
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
                ->asTimestamp(),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
