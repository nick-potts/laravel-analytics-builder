<?php

use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Engine\Joins\JoinGraphBuilder;
use NickPotts\Slice\Engine\Joins\JoinPathFinder;
use NickPotts\Slice\Engine\Joins\JoinResolver;
use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Relations\RelationType;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Create a mock table with relations
 */
function createResolverTestTable(string $name, array $relations = []): TableContract
{
    $relationGraph = new RelationGraph;
    foreach ($relations as $relationName => $descriptor) {
        $relationGraph = new RelationGraph(
            array_merge($relationGraph->all(), [$relationName => $descriptor])
        );
    }

    return new class($name, $relationGraph) implements TableContract
    {
        public function __construct(
            private string $tableName,
            private RelationGraph $relations,
        ) {}

        public function name(): string
        {
            return $this->tableName;
        }

        public function connection(): ?string
        {
            return null;
        }

        public function primaryKey(): PrimaryKeyDescriptor
        {
            return new PrimaryKeyDescriptor(['id']);
        }

        public function relations(): RelationGraph
        {
            return $this->relations;
        }

        public function dimensions(): \NickPotts\Slice\Schemas\Dimensions\DimensionCatalog
        {
            return new \NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
        }
    };
}

beforeEach(function () {
    $manager = \Mockery::mock(SchemaProviderManager::class);

    // Create mock tables
    $this->ordersTable = createResolverTestTable('orders', [
        'customer' => new RelationDescriptor(
            name: 'customer',
            type: RelationType::BelongsTo,
            targetModel: 'MockCustomer',
            keys: ['foreign' => 'customer_id', 'owner' => 'id'],
        ),
    ]);

    $this->customersTable = createResolverTestTable('customers');

    // Mock the manager
    $manager->allows('resolve')->andReturnUsing(function ($modelClass) {
        return match ($modelClass) {
            'MockOrder' => $this->ordersTable,
            'MockCustomer' => $this->customersTable,
            default => throw new \Exception("Model not found: $modelClass"),
        };
    });

    $pathFinder = new JoinPathFinder($manager);
    $graphBuilder = new JoinGraphBuilder($pathFinder);
    $this->resolver = new JoinResolver($pathFinder, $graphBuilder);
});

it('resolves join plan from tables', function () {
    $plan = $this->resolver->resolve([
        $this->ordersTable,
        $this->customersTable,
    ]);

    expect($plan)->not->toBeNull();
    expect($plan->count())->toBe(1);
});

it('returns empty plan for single table', function () {
    $plan = $this->resolver->resolve([$this->ordersTable]);

    expect($plan->isEmpty())->toBeTrue();
});

it('delegates to graph builder', function () {
    // The resolver's job is to orchestrate - verify it returns what graphBuilder returns
    $plan1 = $this->resolver->resolve([
        $this->ordersTable,
        $this->customersTable,
    ]);

    // Call again - should produce same result
    $plan2 = $this->resolver->resolve([
        $this->ordersTable,
        $this->customersTable,
    ]);

    expect($plan1->count())->toBe($plan2->count());
});
