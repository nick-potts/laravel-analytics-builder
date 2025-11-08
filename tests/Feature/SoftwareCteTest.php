<?php

use NickPotts\Slice\Facades\Slice;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Support\Registry;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Models\AdSpend;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

beforeEach(function () {
    // Register test tables
    app(Registry::class)->registerTable(new SoftwareCteOrdersTable);
    app(Registry::class)->registerTable(new SoftwareCteAdSpendTable);

    // Create test customer
    $customer = Customer::create(['name' => 'Test Customer', 'email' => 'test@example.com', 'segment' => 'general']);

    // Create test data in two tables
    Order::create(['customer_id' => $customer->id, 'total' => 1000, 'subtotal' => 400, 'shipping' => 100, 'tax' => 50, 'status' => 'completed', 'created_at' => '2024-01-01']);
    Order::create(['customer_id' => $customer->id, 'total' => 2000, 'subtotal' => 800, 'shipping' => 150, 'tax' => 100, 'status' => 'completed', 'created_at' => '2024-01-01']);
    Order::create(['customer_id' => $customer->id, 'total' => 1500, 'subtotal' => 600, 'shipping' => 120, 'tax' => 80, 'status' => 'completed', 'created_at' => '2024-01-02']);

    AdSpend::create(['spend' => 200, 'impressions' => 10000, 'clicks' => 500, 'channel' => 'google', 'date' => '2024-01-01']);
    AdSpend::create(['spend' => 150, 'impressions' => 8000, 'clicks' => 400, 'channel' => 'facebook', 'date' => '2024-01-01']);
    AdSpend::create(['spend' => 300, 'impressions' => 15000, 'clicks' => 750, 'channel' => 'google', 'date' => '2024-01-02']);
});

it('executes software CTE for cross-table computed metrics', function () {
    $ordersTable = new SoftwareCteOrdersTable;

    $revenue = Sum::make('orders.total')->label('Revenue');
    $adSpend = Sum::make('ad_spend.spend')->label('Ad Spend');
    $roas = Computed::make('orders_total / NULLIF(ad_spend_spend, 0)')
        ->dependsOn($revenue, $adSpend)
        ->label('ROAS')
        ->forTable($ordersTable);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $adSpend,
            $roas,
        ])
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0]['orders_total'])->toBe(3000)
        ->and($results[0]['ad_spend_spend'])->toBe(350)
        ->and($results[0][$roas->key()])->toBeGreaterThan(8.56)
        ->and($results[0][$roas->key()])->toBeLessThan(8.58)
        ->and($results[1]['orders_total'])->toBe(1500)
        ->and($results[1]['ad_spend_spend'])->toBe(300)
        ->and($results[1][$roas->key()])->toBe(5.0);
});

it('executes layered software CTEs (level 1 → level 2)', function () {
    $ordersTable = new SoftwareCteOrdersTable;
    $adSpendTable = new SoftwareCteAdSpendTable;

    // Level 0 - base metrics
    $revenue = Sum::make('orders.total');
    $orderCount = Sum::make('orders.id');
    $adSpend = Sum::make('ad_spend.spend');
    $impressions = Sum::make('ad_spend.impressions');

    // Level 1 - cross-table computed
    $roas = Computed::make('orders_total / NULLIF(ad_spend_spend, 0)')
        ->dependsOn($revenue, $adSpend)
        ->forTable($ordersTable);

    $cpa = Computed::make('ad_spend_spend / NULLIF(orders_id, 0)')
        ->dependsOn($adSpend, $orderCount)
        ->forTable($ordersTable);

    $cpm = Computed::make('(ad_spend_spend / NULLIF(ad_spend_impressions, 0)) * 1000')
        ->dependsOn($adSpend, $impressions)
        ->forTable($adSpendTable);

    // Level 2 - depends on level 1
    $efficiency = Computed::make('('.$roas->key().' / NULLIF('.$cpa->key().', 0))')
        ->dependsOn($roas, $cpa)
        ->forTable($ordersTable);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $orderCount,
            $adSpend,
            $impressions,
            $roas,
            $cpa,
            $cpm,
            $efficiency,
        ])
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0])->toHaveKey($roas->key())
        ->and($results[0])->toHaveKey($cpa->key())
        ->and($results[0])->toHaveKey($efficiency->key())
        ->and($results[0][$efficiency->key()])->not()->toBeNull();
});

it('handles NULL values in software CTE expressions', function () {
    // Add day with no ad spend
    $customer = Customer::first();
    Order::create(['customer_id' => $customer->id, 'total' => 1000, 'subtotal' => 400, 'shipping' => 100, 'tax' => 50, 'status' => 'completed', 'created_at' => '2024-01-03']);

    $ordersTable = new SoftwareCteOrdersTable;

    $revenue = Sum::make('orders.total');
    $adSpend = Sum::make('ad_spend.spend');
    $roas = Computed::make('orders_total / NULLIF(ad_spend_spend, 0)')
        ->dependsOn($revenue, $adSpend)
        ->forTable($ordersTable);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $adSpend,
            $roas,
        ])
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(3)
        ->and($results[2]['orders_total'])->toBe(1000)
        ->and($results[2]['ad_spend_spend'])->toBe(0)
        ->and($results[2][$roas->key()])->toBeNull(); // Division by zero
});

it('combines database CTEs and software CTEs in same query', function () {
    $ordersTable = new SoftwareCteOrdersTable;

    // Orders table - database CTE
    $revenue = Sum::make('orders.total');
    $subtotal = Sum::make('orders.subtotal');

    $grossProfit = Computed::make('orders_total - orders_subtotal')
        ->dependsOn($revenue, $subtotal)
        ->forTable($ordersTable);

    // Ad spend table
    $adSpend = Sum::make('ad_spend.spend');

    // Cross-table computed (software CTE) depending on database CTE
    $profitRoas = Computed::make('('.$grossProfit->key().' / NULLIF(ad_spend_spend, 0))')
        ->dependsOn($grossProfit, $adSpend)
        ->forTable($ordersTable);

    $results = Slice::query()
        ->metrics([
            $revenue,
            $subtotal,
            $grossProfit,
            $adSpend,
            $profitRoas,
        ])
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0][$grossProfit->key()])->toBe(1800) // 3000 - 1200
        ->and($results[0]['ad_spend_spend'])->toBe(350)
        ->and($results[0][$profitRoas->key()])->toBeGreaterThan(5.13)
        ->and($results[0][$profitRoas->key()])->toBeLessThan(5.15);
});

// Test helper table classes
class SoftwareCteOrdersTable extends Table
{
    protected string $table = 'orders';

    public function dimensions(): array
    {
        return [
            // Use 'date' as dimension name for consistency with ad_spend table
            // even though the actual column is 'created_at'
            TimeDimension::class => TimeDimension::make('date')
                ->column('created_at')
                ->asTimestamp(),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}

class SoftwareCteAdSpendTable extends Table
{
    protected string $table = 'ad_spend';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('date')
                ->asDate(),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
