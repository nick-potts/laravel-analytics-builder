<?php

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\Joins\JoinGraphBuilder;
use NickPotts\Slice\Engine\Joins\JoinPathFinder;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Relations\RelationType;
use NickPotts\Slice\Support\CompiledSchema;
use NickPotts\Slice\Support\SliceDefinition;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;

/**
 * Create a mock table with relations
 */
function createGraphBuilderTestTable(string $name, array $relations = []): SliceSource
{
    $relationGraph = new RelationGraph;
    foreach ($relations as $relationName => $descriptor) {
        $relationGraph = new RelationGraph(
            array_merge($relationGraph->all(), [$relationName => $descriptor])
        );
    }

    return new class($name, $relationGraph) implements SliceSource
    {
        public function __construct(
            private string $tableName,
            private RelationGraph $relations,
        ) {}

        public function identifier(): string
        {
            return 'mock:'.$this->tableName;
        }

        public function name(): string
        {
            return $this->tableName;
        }

        public function provider(): string
        {
            return 'mock';
        }

        public function connection(): string
        {
            return 'eloquent:default';
        }

        public function relations(): RelationGraph
        {
            return $this->relations;
        }

        public function dimensions(): \NickPotts\Slice\Schemas\Dimensions\DimensionCatalog
        {
            return new \NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
        }

        public function sqlTable(): ?string
        {
            return $this->tableName;
        }

        public function sql(): ?string
        {
            return null;
        }

        public function meta(): array
        {
            return [];
        }
    };
}

beforeEach(function () {
    // Create mock tables
    $this->ordersTable = createGraphBuilderTestTable('orders', [
        'customer' => new RelationDescriptor(
            name: 'customer',
            type: RelationType::BelongsTo,
            targetTableIdentifier: 'mock:customers',
            keys: ['foreign' => 'customer_id', 'owner' => 'id'],
        ),
        'items' => new RelationDescriptor(
            name: 'items',
            type: RelationType::HasMany,
            targetTableIdentifier: 'mock:order_items',
            keys: ['local' => 'id', 'foreign' => 'order_id'],
        ),
    ]);

    $this->customersTable = createGraphBuilderTestTable('customers');

    $this->orderItemsTable = createGraphBuilderTestTable('order_items', [
        'order' => new RelationDescriptor(
            name: 'order',
            type: RelationType::BelongsTo,
            targetTableIdentifier: 'mock:orders',
            keys: ['foreign' => 'order_id', 'owner' => 'id'],
        ),
    ]);

    $this->productsTable = createGraphBuilderTestTable('products');

    // Create compiled schema with mock tables
    $this->schema = new CompiledSchema(
        tablesByIdentifier: [
            'mock:orders' => SliceDefinition::fromSource($this->ordersTable),
            'mock:customers' => SliceDefinition::fromSource($this->customersTable),
            'mock:order_items' => SliceDefinition::fromSource($this->orderItemsTable),
            'mock:products' => SliceDefinition::fromSource($this->productsTable),
        ],
        tablesByName: [
            'orders' => SliceDefinition::fromSource($this->ordersTable),
            'customers' => SliceDefinition::fromSource($this->customersTable),
            'order_items' => SliceDefinition::fromSource($this->orderItemsTable),
            'products' => SliceDefinition::fromSource($this->productsTable),
        ],
        tableProviders: [
            'mock:orders' => 'mock',
            'mock:customers' => 'mock',
            'mock:order_items' => 'mock',
            'mock:products' => 'mock',
        ],
        relations: [
            'mock:orders' => $this->ordersTable->relations(),
            'mock:customers' => $this->customersTable->relations(),
            'mock:order_items' => $this->orderItemsTable->relations(),
            'mock:products' => $this->productsTable->relations(),
        ],
        dimensions: [
            'mock:orders' => new DimensionCatalog,
            'mock:customers' => new DimensionCatalog,
            'mock:order_items' => new DimensionCatalog,
            'mock:products' => new DimensionCatalog,
        ],
        connectionIndex: [
            'eloquent:default' => [
                'mock:orders',
                'mock:customers',
                'mock:order_items',
                'mock:products',
            ],
        ],
    );

    $this->pathFinder = new JoinPathFinder($this->schema);
    $this->builder = new JoinGraphBuilder($this->pathFinder);
});

it('returns empty plan for single table', function () {
    $plan = $this->builder->build([$this->ordersTable]);

    expect($plan->isEmpty())->toBeTrue();
    expect($plan->count())->toBe(0);
});

it('returns empty plan for no tables', function () {
    $plan = $this->builder->build([]);

    expect($plan->isEmpty())->toBeTrue();
});

it('connects two related tables', function () {
    $plan = $this->builder->build([$this->ordersTable, $this->customersTable]);

    expect($plan->count())->toBe(1);
    $joins = $plan->all();
    expect($joins[0]->fromTable)->toBe('orders');
    expect($joins[0]->toTable)->toBe('customers');
});

it('connects three tables with greedy approach', function () {
    // Start with orders, connect to items (HasMany), then to customers (BelongsTo)
    $plan = $this->builder->build([
        $this->ordersTable,
        $this->orderItemsTable,
        $this->customersTable,
    ]);

    expect($plan->count())->toBe(2);
    $joins = $plan->all();
    expect($joins[0]->fromTable)->toBe('orders');
    expect($joins[0]->toTable)->toBe('order_items');
    expect($joins[1]->fromTable)->toBe('orders');
    expect($joins[1]->toTable)->toBe('customers');
});

it('deduplicates joins when same join appears in multiple paths', function () {
    // All three tables need to connect, but orders->customers should only appear once
    $plan = $this->builder->build([
        $this->ordersTable,
        $this->orderItemsTable,
        $this->customersTable,
    ]);

    // Verify deduplication - should have exactly 2 unique joins
    expect($plan->count())->toBe(2);

    $dedupeKeys = [];
    foreach ($plan->all() as $join) {
        $key = $join->fromTable.'->'.$join->toTable;
        expect($dedupeKeys)->not->toHaveKey($key);
        $dedupeKeys[$key] = true;
    }
});

it('skips tables that cannot be connected', function () {
    // Product has no relations and cannot be connected
    $plan = $this->builder->build([
        $this->ordersTable,
        $this->productsTable,
    ]);

    // Should return empty plan since products cannot be connected
    expect($plan->isEmpty())->toBeTrue();
});

it('returns all unique tables from plan', function () {
    $plan = $this->builder->build([
        $this->ordersTable,
        $this->orderItemsTable,
        $this->customersTable,
    ]);

    $tables = $plan->tables();
    expect($tables)->toContain('orders');
    expect($tables)->toContain('order_items');
    expect($tables)->toContain('customers');
});
