<?php

use NickPotts\Slice\Engine\Joins\JoinPathFinder;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationType;
use NickPotts\Slice\Support\CompiledSchema;
use NickPotts\Slice\Support\SliceDefinition;
use NickPotts\Slice\Tests\Factories\MockTableFactory;

beforeEach(function () {
    // Create mock tables
    $this->ordersTable = MockTableFactory::create('orders')->relations([
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
    ])->build();

    $this->customersTable = MockTableFactory::create('customers')->build();

    $this->orderItemsTable = MockTableFactory::create('order_items')->relations([
        'order' => new RelationDescriptor(
            name: 'order',
            type: RelationType::BelongsTo,
            targetTableIdentifier: 'mock:orders',
            keys: ['foreign' => 'order_id', 'owner' => 'id'],
        ),
    ])->build();

    $this->productsTable = MockTableFactory::create('products')->build();

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

    $this->finder = new JoinPathFinder($this->schema);
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
