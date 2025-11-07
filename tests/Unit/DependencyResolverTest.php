<?php

use NickPotts\Slice\Engine\DependencyResolver;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Tables\Table;

beforeEach(function () {
    $this->resolver = new DependencyResolver;
});

it('groups metrics by dependency level correctly', function () {
    $table = createMockTable('orders');

    $metrics = [
        [
            'key' => 'orders_revenue',
            'table' => $table,
            'metric' => Sum::make('orders.total')->label('Revenue'),
        ],
        [
            'key' => 'orders_cost',
            'table' => $table,
            'metric' => Sum::make('orders.cost')->label('Cost'),
        ],
        [
            'key' => 'orders_profit',
            'table' => $table,
            'metric' => Computed::make('orders_revenue - orders_cost')
                ->dependsOn('orders_revenue', 'orders_cost')
                ->label('Profit')
                ->forTable($table),
        ],
        [
            'key' => 'orders_margin',
            'table' => $table,
            'metric' => Computed::make('(orders_profit / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_profit', 'orders_revenue')
                ->label('Margin')
                ->forTable($table),
        ],
    ];

    $levels = $this->resolver->groupByLevel($metrics);

    expect($levels)->toHaveKey(0)
        ->and($levels)->toHaveKey(1)
        ->and($levels)->toHaveKey(2)
        ->and($levels[0])->toHaveCount(2) // revenue, cost
        ->and($levels[1])->toHaveCount(1) // profit
        ->and($levels[2])->toHaveCount(1) // margin
        ->and($levels[0][0]['key'])->toBe('orders_revenue')
        ->and($levels[0][1]['key'])->toBe('orders_cost')
        ->and($levels[1][0]['key'])->toBe('orders_profit')
        ->and($levels[2][0]['key'])->toBe('orders_margin');
});

it('splits metrics into database and software computable strategies', function () {
    $ordersTable = createMockTable('orders');
    $adSpendTable = createMockTable('ad_spend');

    $metrics = [
        // Same table - database computable
        [
            'key' => 'orders_revenue',
            'table' => $ordersTable,
            'metric' => Sum::make('orders.total'),
        ],
        [
            'key' => 'orders_cost',
            'table' => $ordersTable,
            'metric' => Sum::make('orders.cost'),
        ],
        [
            'key' => 'orders_profit',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue - orders_cost')
                ->dependsOn('orders_revenue', 'orders_cost')
                ->forTable($ordersTable),
        ],
        // Different table - base metric
        [
            'key' => 'ad_spend_spend',
            'table' => $adSpendTable,
            'metric' => Sum::make('ad_spend.spend'),
        ],
        // Cross-table - software computable
        [
            'key' => 'orders_roas',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(ad_spend_spend, 0)')
                ->dependsOn('orders_revenue', 'ad_spend_spend')
                ->forTable($ordersTable),
        ],
    ];

    $split = $this->resolver->splitByComputationStrategy($metrics);

    expect($split)->toHaveKey('database')
        ->and($split)->toHaveKey('software')
        ->and($split['database'])->toHaveCount(4) // revenue, cost, profit, spend
        ->and($split['software'])->toHaveCount(1) // roas
        ->and($split['software'][0]['key'])->toBe('orders_roas');
});

it('detects circular dependencies', function () {
    $table = createMockTable('orders');

    // Create a circular dependency: A depends on B, B depends on A
    $metricA = Computed::make('orders_b + 100')
        ->dependsOn('orders_b')
        ->forTable($table);

    $metricB = Computed::make('orders_a + 100')
        ->dependsOn('orders_a')
        ->forTable($table);

    $metrics = [
        ['key' => 'orders_a', 'table' => $table, 'metric' => $metricA],
        ['key' => 'orders_b', 'table' => $table, 'metric' => $metricB],
    ];

    expect(fn () => $this->resolver->groupByLevel($metrics))
        ->toThrow(\RuntimeException::class, 'Circular dependency detected');
});

it('handles complex multi-level dependencies', function () {
    $table = createMockTable('orders');

    $metrics = [
        // Level 0
        ['key' => 'orders_revenue', 'table' => $table, 'metric' => Sum::make('orders.total')],
        ['key' => 'orders_cost', 'table' => $table, 'metric' => Sum::make('orders.cost')],
        ['key' => 'orders_shipping', 'table' => $table, 'metric' => Sum::make('orders.shipping')],
        ['key' => 'orders_count', 'table' => $table, 'metric' => Sum::make('orders.id')],

        // Level 1
        [
            'key' => 'orders_gross_profit',
            'table' => $table,
            'metric' => Computed::make('orders_revenue - orders_cost')
                ->dependsOn('orders_revenue', 'orders_cost')
                ->forTable($table),
        ],
        [
            'key' => 'orders_net_profit',
            'table' => $table,
            'metric' => Computed::make('orders_revenue - orders_cost - orders_shipping')
                ->dependsOn('orders_revenue', 'orders_cost', 'orders_shipping')
                ->forTable($table),
        ],

        // Level 2
        [
            'key' => 'orders_gross_margin',
            'table' => $table,
            'metric' => Computed::make('(orders_gross_profit / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_gross_profit', 'orders_revenue')
                ->forTable($table),
        ],
        [
            'key' => 'orders_net_margin',
            'table' => $table,
            'metric' => Computed::make('(orders_net_profit / NULLIF(orders_revenue, 0)) * 100')
                ->dependsOn('orders_net_profit', 'orders_revenue')
                ->forTable($table),
        ],

        // Level 3 - depends on level 2
        [
            'key' => 'orders_efficiency_score',
            'table' => $table,
            'metric' => Computed::make('orders_gross_margin + orders_net_margin')
                ->dependsOn('orders_gross_margin', 'orders_net_margin')
                ->forTable($table),
        ],
    ];

    $levels = $this->resolver->groupByLevel($metrics);

    expect($levels)->toHaveKey(0)
        ->and($levels)->toHaveKey(1)
        ->and($levels)->toHaveKey(2)
        ->and($levels)->toHaveKey(3)
        ->and($levels[0])->toHaveCount(4)
        ->and($levels[1])->toHaveCount(2)
        ->and($levels[2])->toHaveCount(2)
        ->and($levels[3])->toHaveCount(1);
});

it('correctly identifies when computed metric needs software computation', function () {
    $ordersTable = createMockTable('orders');
    $customersTable = createMockTable('customers');

    $metrics = [
        ['key' => 'orders_revenue', 'table' => $ordersTable, 'metric' => Sum::make('orders.total')],
        ['key' => 'customers_count', 'table' => $customersTable, 'metric' => Sum::make('customers.id')],
        [
            'key' => 'orders_revenue_per_customer',
            'table' => $ordersTable,
            'metric' => Computed::make('orders_revenue / NULLIF(customers_count, 0)')
                ->dependsOn('orders_revenue', 'customers_count')
                ->forTable($ordersTable),
        ],
    ];

    $split = $this->resolver->splitByComputationStrategy($metrics);

    expect($split['database'])->toHaveCount(2) // revenue, count
        ->and($split['software'])->toHaveCount(1) // revenue_per_customer
        ->and($split['software'][0]['key'])->toBe('orders_revenue_per_customer');
});

it('resolves dependencies in topological order', function () {
    $table = createMockTable('orders');

    $metrics = [
        // Add in random order
        [
            'key' => 'orders_margin',
            'table' => $table,
            'metric' => Computed::make('orders_profit / NULLIF(orders_revenue, 0)')
                ->dependsOn('orders_profit', 'orders_revenue')
                ->forTable($table),
        ],
        ['key' => 'orders_revenue', 'table' => $table, 'metric' => Sum::make('orders.total')],
        ['key' => 'orders_cost', 'table' => $table, 'metric' => Sum::make('orders.cost')],
        [
            'key' => 'orders_profit',
            'table' => $table,
            'metric' => Computed::make('orders_revenue - orders_cost')
                ->dependsOn('orders_revenue', 'orders_cost')
                ->forTable($table),
        ],
    ];

    $resolved = $this->resolver->resolve($metrics);
    $keys = array_keys($resolved);

    // revenue and cost should come before profit
    $revenueIndex = array_search('orders_revenue', $keys);
    $costIndex = array_search('orders_cost', $keys);
    $profitIndex = array_search('orders_profit', $keys);
    $marginIndex = array_search('orders_margin', $keys);

    expect($revenueIndex)->toBeLessThan($profitIndex)
        ->and($costIndex)->toBeLessThan($profitIndex)
        ->and($profitIndex)->toBeLessThan($marginIndex);
});

// Helper function to create mock table
function createMockTable(string $name): Table
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
