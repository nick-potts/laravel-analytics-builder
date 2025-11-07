<?php

use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Slice;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Models\AdSpend;
use Workbench\App\Models\Order;

beforeEach(function () {
    // Create test data in two tables
    Order::create(['total' => 1000, 'item_cost' => 400, 'country' => 'US', 'created_at' => '2024-01-01']);
    Order::create(['total' => 2000, 'item_cost' => 800, 'country' => 'US', 'created_at' => '2024-01-01']);
    Order::create(['total' => 1500, 'item_cost' => 600, 'country' => 'CA', 'created_at' => '2024-01-02']);

    AdSpend::create(['spend' => 200, 'impressions' => 10000, 'date' => '2024-01-01']);
    AdSpend::create(['spend' => 150, 'impressions' => 8000, 'date' => '2024-01-01']);
    AdSpend::create(['spend' => 300, 'impressions' => 15000, 'date' => '2024-01-02']);
});

it('executes software CTE for cross-table computed metrics', function () {
    $metrics = [
        // Orders table (MySQL)
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('total')->label('Revenue'),
        ],
        // Ad spend table (could be ClickHouse in real scenario)
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('spend')->label('Ad Spend'),
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
    Order::create(['total' => 500, 'item_cost' => 200, 'country' => 'US', 'created_at' => '2024-01-01', 'items_count' => 5]);
    Order::first()->update(['items_count' => 10]);
    Order::skip(1)->first()->update(['items_count' => 20]);

    $metrics = [
        // Level 0 - base metrics
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'orders_count',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('items_count'),
        ],
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('spend'),
        ],
        [
            'key' => 'ad_spend_impressions',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('impressions'),
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
    Order::create(['total' => 1000, 'item_cost' => 400, 'country' => 'US', 'created_at' => '2024-01-03']);

    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('spend'),
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
            'metric' => Sum::make('total'),
        ],
        [
            'key' => 'orders_item_cost',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Sum::make('item_cost'),
        ],
        [
            'key' => 'orders_gross_profit',
            'table' => new SoftwareCteOrdersTable,
            'metric' => Computed::make('orders_revenue - orders_item_cost')
                ->dependsOn('orders_revenue', 'orders_item_cost')
                ->forTable(new SoftwareCteOrdersTable),
        ],
        // Ad spend table
        [
            'key' => 'ad_spend_spend',
            'table' => new SoftwareCteAdSpendTable,
            'metric' => Sum::make('spend'),
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
