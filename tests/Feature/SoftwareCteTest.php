<?php

use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Slice;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Models\AdSpend;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

beforeEach(function () {
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
    $metrics = [
        // Orders table (MySQL)
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('orders.total')->label('Revenue'),
        ],
        // Ad spend table (could be ClickHouse in real scenario)
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('ad_spend.spend')->label('Ad Spend'),
        ],
        // Cross-table computed metric (software CTE!)
        [
            'key' => 'marketing_roas',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->label('ROAS')
                ->forTable(new SoftwareCteOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0]['orders_revenue'])->toBe(3000)
        ->and($results[0]['ad_spend_spend'])->toBe(350)
        ->and($results[0]['marketing_roas'])->toBeCloseTo(8.57, 0.01) // 3000 / 350
        ->and($results[1]['orders_revenue'])->toBe(1500)
        ->and($results[1]['ad_spend_spend'])->toBe(300)
        ->and($results[1]['marketing_roas'])->toBe(5.0); // 1500 / 300
});

it('executes layered software CTEs (level 1 → level 2)', function () {
    // Just count the IDs instead of using a non-existent items_count column
    $metrics = [
        // Level 0 - base metrics
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'orders_count',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('orders.id'),
        ],
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('ad_spend.spend'),
        ],
        [
            'key' => 'ad_spend_impressions',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('ad_spend.impressions'),
        ],
        // Level 1 - cross-table computed
        [
            'key' => 'marketing_roas',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable(new SoftwareCteOrdersTable),
        ],
        [
            'key' => 'marketing_cpa',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('ad_spend_spend / NULLIF(orders_count, 0)')
                ->dependsOn('ad_spend_spend', 'orders_count')
                ->forTable(new SoftwareCteOrdersTable),
        ],
        [
            'key' => 'ad_spend_cpm',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Computed::make('(ad_spend_spend / NULLIF(ad_spend_impressions, 0)) * 1000')
                ->dependsOn('ad_spend_spend', 'ad_spend_impressions')
                ->forTable(new SoftwareCteAdSpendTable),
        ],
        // Level 2 - depends on level 1
        [
            'key' => 'marketing_efficiency',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('marketing_roas / NULLIF(marketing_cpa, 0)')
                ->dependsOn('marketing_roas', 'marketing_cpa')
                ->forTable(new SoftwareCteOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0])->toHaveKey('marketing_roas')
        ->and($results[0])->toHaveKey('marketing_cpa')
        ->and($results[0])->toHaveKey('marketing_efficiency')
        ->and($results[0]['marketing_efficiency'])->toBeFloat();
});

it('handles NULL values in software CTE expressions', function () {
    // Add day with no ad spend
    $customer = Customer::first();
    Order::create(['customer_id' => $customer->id, 'total' => 1000, 'subtotal' => 400, 'shipping' => 100, 'tax' => 50, 'status' => 'completed', 'created_at' => '2024-01-03']);

    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('ad_spend.spend'),
        ],
        [
            'key' => 'marketing_roas',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable(new SoftwareCteOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(3)
        ->and($results[2]['orders_revenue'])->toBe(1000)
        ->and($results[2]['ad_spend_spend'])->toBe(0)
        ->and($results[2]['marketing_roas'])->toBeNull(); // Division by zero
});

it('combines database CTEs and software CTEs in same query', function () {
    $metrics = [
        // Orders table - database CTE
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'orders_subtotal',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('orders.subtotal'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_subtotal')
                ->dependsOn('orders_revenue', 'orders_subtotal')
                ->forTable(new SoftwareCteOrdersTable),
        ],
        // Ad spend table
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('ad_spend.spend'),
        ],
        // Cross-table computed (software CTE) depending on database CTE
        [
            'key' => 'marketing_profit_roas',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('orders_gross_profit / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_gross_profit', 'ad_spend_spend')
                ->forTable(new SoftwareCteOrdersTable),
        ],
    ];

    $results = Slice::query()
        ->metricsRaw($metrics)
        ->dimensions([
            TimeDimension::make('date')->daily(),
        ])
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results[0]['orders_gross_profit'])->toBe(1800) // 3000 - 1200
        ->and($results[0]['ad_spend_spend'])->toBe(350)
        ->and($results[0]['marketing_profit_roas'])->toBeCloseTo(5.14, 0.01); // 1800 / 350
});

// Test helper table classes
class SoftwareCteOrdersTable extends Table
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

    public function crossJoins(): array
    {
        return [
            'date' => [
                'table' => new SoftwareCteAdSpendTable,
                'left' => 'created_at',
                'right' => 'date',
                'type' => 'date',
            ],
        ];
    }
}

class SoftwareCteAdSpendTable extends Table
{
    protected string $table = 'ad_spend';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('date')
                ->asDate()
                ->minGranularity('day'),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
