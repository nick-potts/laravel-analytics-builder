<?php

use NickPotts\Slice\Engine\DependencyResolver;
use NickPotts\Slice\Engine\PostProcessor;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Tables\Table;

beforeEach(function () {
    $this->processor = new PostProcessor(new DependencyResolver);
});

it('processes basic software CTE with simple arithmetic', function () {
    $ordersTable = createTestTable('orders');
    $adSpendTable = createTestTable('ad_spend');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'ad_spend_spend', 'table' => $adSpendTable, 'metric' => Sum::make('spend')],
        [
            'key' => 'marketing_roas',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / ad_spend_spend')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable($ordersTable),
        ],
    ];

    $rows = [
        ['date' => '2024-01-01', 'orders_revenue' => 1000, 'ad_spend_spend' => 200],
        ['date' => '2024-01-02', 'orders_revenue' => 1500, 'ad_spend_spend' => 300],
    ];

    $result = $this->processor->process($rows, $metrics);

    expect($result->toArray())->toHaveCount(2)
        ->and($result->toArray()[0]['marketing_roas'])->toBe(5.0)
        ->and($result->toArray()[1]['marketing_roas'])->toBe(5.0);
});

it('handles NULLIF to prevent division by zero', function () {
    $ordersTable = createTestTable('orders');
    $adSpendTable = createTestTable('ad_spend');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'ad_spend_spend', 'table' => $adSpendTable, 'metric' => Sum::make('spend')],
        [
            'key' => 'marketing_roas',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable($ordersTable),
        ],
    ];

    $rows = [
        ['date' => '2024-01-01', 'orders_revenue' => 1000, 'ad_spend_spend' => 0],
        ['date' => '2024-01-02', 'orders_revenue' => 1500, 'ad_spend_spend' => 300],
    ];

    $result = $this->processor->process($rows, $metrics);

    expect($result->toArray())->toHaveCount(2)
        ->and($result->toArray()[0]['marketing_roas'])->toBeNull()
        ->and($result->toArray()[1]['marketing_roas'])->toBe(5.0);
});

it('processes layered software CTEs (level 1 → level 2)', function () {
    $ordersTable = createTestTable('orders');
    $adSpendTable = createTestTable('ad_spend');

    $metrics = [
        // Level 0
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'orders_count', 'table' => $ordersTable, 'metric' => Sum::make('id')],
        ['key' => 'ad_spend_spend', 'table' => $adSpendTable, 'metric' => Sum::make('spend')],

        // Level 1
        [
            'key' => 'marketing_roas',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable($ordersTable),
        ],
        [
            'key' => 'marketing_cpa',
            'table' => $ordersTable,
            'metric' => Computed::make('ad_spend_spend / NULLIF(orders_count, 0)')
                ->dependsOn('ad_spend_spend', 'orders_count')
                ->forTable($ordersTable),
        ],

        // Level 2 - depends on level 1
        [
            'key' => 'marketing_efficiency',
            'table' => $ordersTable,
            'metric' => Computed::make('marketing_roas / NULLIF(marketing_cpa, 0)')
                ->dependsOn('marketing_roas', 'marketing_cpa')
                ->forTable($ordersTable),
        ],
    ];

    $rows = [
        [
            'date' => '2024-01-01',
            'orders_revenue' => 1000,
            'orders_count' => 10,
            'ad_spend_spend' => 200,
        ],
    ];

    $result = $this->processor->process($rows, $metrics);
    $data = $result->toArray()[0];

    expect($data['marketing_roas'])->toBe(5.0)
        ->and($data['marketing_cpa'])->toBe(20.0)
        ->and($data['marketing_efficiency'])->toBe(0.25);
});

it('normalizes numeric values from database strings', function () {
    $ordersTable = createTestTable('orders');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'orders_count', 'table' => $ordersTable, 'metric' => Sum::make('id')],
    ];

    // Simulate database returning string values
    $rows = [
        ['orders_revenue' => '1000.50', 'orders_count' => '10'],
        ['orders_revenue' => '2000', 'orders_count' => '20'],
    ];

    $result = $this->processor->process($rows, $metrics);
    $data = $result->toArray();

    expect($data[0]['orders_revenue'])->toBe(1000.5)
        ->and($data[0]['orders_count'])->toBe(10)
        ->and($data[1]['orders_revenue'])->toBe(2000)
        ->and($data[1]['orders_count'])->toBe(20);
});

it('handles complex expressions with multiple operations', function () {
    $ordersTable = createTestTable('orders');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'orders_cost', 'table' => $ordersTable, 'metric' => Sum::make('cost')],
        ['key' => 'orders_shipping', 'table' => $ordersTable, 'metric' => Sum::make('shipping')],
        [
            'key' => 'orders_net_profit',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue - orders_cost - orders_shipping')
                ->dependsOn('orders_revenue', 'orders_cost', 'orders_shipping')
                ->forTable($ordersTable),
        ],
        [
            'key' => 'orders_net_margin',
            'table' => $ordersTable,
            'metric' => Computed::make('(orders_net_profit / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_net_profit', 'orders_revenue')
                ->forTable($ordersTable),
        ],
    ];

    $rows = [
        [
            'orders_revenue' => 1000,
            'orders_cost' => 400,
            'orders_shipping' => 100,
        ],
    ];

    $result = $this->processor->process($rows, $metrics);
    $data = $result->toArray()[0];

    expect($data['orders_net_profit'])->toBe(500)
        ->and($data['orders_net_margin'])->toBe(50.0);
});

it('returns null when dependencies are missing', function () {
    $ordersTable = createTestTable('orders');
    $adSpendTable = createTestTable('ad_spend');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'ad_spend_spend', 'table' => $adSpendTable, 'metric' => Sum::make('spend')],
        [
            'key' => 'marketing_roas',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / ad_spend_spend')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable($ordersTable),
        ],
    ];

    // Missing ad_spend_spend in row
    $rows = [
        ['orders_revenue' => 1000],
    ];

    $result = $this->processor->process($rows, $metrics);

    expect($result->toArray()[0]['marketing_roas'])->toBeNull();
});

it('processes multiple rows independently', function () {
    $ordersTable = createTestTable('orders');
    $adSpendTable = createTestTable('ad_spend');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'ad_spend_spend', 'table' => $adSpendTable, 'metric' => Sum::make('spend')],
        [
            'key' => 'marketing_roas',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable($ordersTable),
        ],
    ];

    $rows = [
        ['date' => '2024-01-01', 'orders_revenue' => 1000, 'ad_spend_spend' => 200],
        ['date' => '2024-01-02', 'orders_revenue' => 2000, 'ad_spend_spend' => 500],
        ['date' => '2024-01-03', 'orders_revenue' => 1500, 'ad_spend_spend' => 0],
    ];

    $result = $this->processor->process($rows, $metrics);
    $data = $result->toArray();

    expect($data)->toHaveCount(3)
        ->and($data[0]['marketing_roas'])->toBe(5.0)
        ->and($data[1]['marketing_roas'])->toBe(4.0)
        ->and($data[2]['marketing_roas'])->toBeNull();
});

it('does not modify rows when there are no software-computed metrics', function () {
    $ordersTable = createTestTable('orders');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('total')],
        ['key' => 'orders_count', 'table' => $ordersTable, 'metric' => Sum::make('id')],
    ];

    $rows = [
        ['orders_revenue' => '1000', 'orders_count' => '10'],
        ['orders_revenue' => '2000', 'orders_count' => '20'],
    ];

    $result = $this->processor->process($rows, $metrics);
    $data = $result->toArray();

    expect($data)->toHaveCount(2)
        ->and($data[0]['orders_revenue'])->toBe(1000)
        ->and($data[0]['orders_count'])->toBe(10)
        ->and($data[0])->not->toHaveKey('computed_metric');
});

// Helper function to create test table
function createTestTable(string $name): Table
{
    return new class($name) extends Table
    {
        public function __construct(protected string $tableName)
        {
            $this->table = $tableName;
        }

        public function dimensions(): array
        {
            return [];
        }

        public function relations(): array
        {
            return [];
        }
    };
}
