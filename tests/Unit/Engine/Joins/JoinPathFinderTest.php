<?php

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\Joins\JoinPathFinder;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Relations\RelationType;
use NickPotts\Slice\Support\SchemaProviderManager;
use NickPotts\Slice\Support\SliceDefinition;

/**
 * Create a mock table with relations
 */
function createMockTable(string $name, array $relations = []): SliceSource
{
    $relationGraph = new RelationGraph;
    foreach ($relations as $relationName => $descriptor) {
        $relationGraph = new RelationGraph(
            array_merge($relationGraph->all(), [$relationName => $descriptor])
        );
    }

    return new class($name, $relationGraph) implements SliceSource {
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
    $this->manager = new SchemaProviderManager;

    // Create mock tables
    $this->ordersTable = createMockTable('orders', [
        'customer' => new RelationDescriptor(
            name: 'customer',
            type: RelationType::BelongsTo,
            targetModel: 'MockCustomer',
            keys: ['foreign' => 'customer_id', 'owner' => 'id'],
        ),
        'items' => new RelationDescriptor(
            name: 'items',
            type: RelationType::HasMany,
            targetModel: 'MockOrderItem',
            keys: ['local' => 'id', 'foreign' => 'order_id'],
        ),
    ]);

    $this->customersTable = createMockTable('customers');

    $this->orderItemsTable = createMockTable('order_items', [
        'order' => new RelationDescriptor(
            name: 'order',
            type: RelationType::BelongsTo,
            targetModel: 'MockOrder',
            keys: ['foreign' => 'order_id', 'owner' => 'id'],
        ),
    ]);

    $this->productsTable = createMockTable('products');

    // Mock the manager to resolve model classes to tables
    $ordersTable = $this->ordersTable;
    $customersTable = $this->customersTable;
    $orderItemsTable = $this->orderItemsTable;
    $productsTable = $this->productsTable;

    $this->manager = \Mockery::mock(SchemaProviderManager::class);
    $this->manager->allows('resolve')->andReturnUsing(function ($modelClass) use ($ordersTable, $customersTable, $orderItemsTable, $productsTable) {
        $result = match ($modelClass) {
            'MockOrder' => $ordersTable,
            'MockCustomer' => $customersTable,
            'MockOrderItem' => $orderItemsTable,
            'MockProduct' => $productsTable,
            default => throw new \Exception("Model not found: $modelClass"),
        };
        return SliceDefinition::fromSource($result);
    });

    $this->finder = new JoinPathFinder($this->manager);
});

it('finds direct relation path', function () {
    $path = $this->finder->find($this->ordersTable, $this->customersTable);

    expect($path)->not->toBeNull();
    expect($path)->toHaveCount(1);
    expect($path[0]->fromTable)->toBe('orders');
    expect($path[0]->toTable)->toBe('customers');
    expect($path[0]->relation->type)->toBe(RelationType::BelongsTo);
});

it('finds multi-hop path', function () {
    $path = $this->finder->find($this->orderItemsTable, $this->customersTable);

    expect($path)->not->toBeNull();
    expect($path)->toHaveCount(2);
    expect($path[0]->fromTable)->toBe('order_items');
    expect($path[0]->toTable)->toBe('orders');
    expect($path[1]->fromTable)->toBe('orders');
    expect($path[1]->toTable)->toBe('customers');
});

it('returns empty array for same table', function () {
    $path = $this->finder->find($this->ordersTable, $this->ordersTable);

    expect($path)->toEqual([]);
});

it('returns null when no path exists', function () {
    // Product has no relations, so it cannot be reached from orders
    $path = $this->finder->find($this->ordersTable, $this->productsTable);

    expect($path)->toBeNull();
});

it('finds path through HasMany relation', function () {
    $path = $this->finder->find($this->ordersTable, $this->orderItemsTable);

    expect($path)->not->toBeNull();
    expect($path)->toHaveCount(1);
    expect($path[0]->fromTable)->toBe('orders');
    expect($path[0]->toTable)->toBe('order_items');
    expect($path[0]->relation->type)->toBe(RelationType::HasMany);
});

it('prevents cycles in BFS', function () {
    $path = $this->finder->find($this->ordersTable, $this->ordersTable);

    expect($path)->toEqual([]);
});
