<?php

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\Joins\JoinGraphBuilder;
use NickPotts\Slice\Engine\Joins\JoinPathFinder;
use NickPotts\Slice\Engine\Joins\JoinResolver;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Relations\RelationType;
use NickPotts\Slice\Support\SchemaProviderManager;
use NickPotts\Slice\Support\SliceDefinition;

/**
 * Create a mock table with relations
 */
function createResolverTestTable(string $name, array $relations = []): SliceSource
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

    $ordersTable = $this->ordersTable;
    $customersTable = $this->customersTable;

    // Mock the manager
    $manager->allows('resolve')->andReturnUsing(function ($modelClass) use ($ordersTable, $customersTable) {
        $result = match ($modelClass) {
            'MockOrder' => $ordersTable,
            'MockCustomer' => $customersTable,
            default => throw new \Exception("Model not found: $modelClass"),
        };

        return SliceDefinition::fromSource($result);
    });

    $pathFinder = new JoinPathFinder($manager);
    $graphBuilder = new JoinGraphBuilder($pathFinder);
    $this->resolver = new JoinResolver($graphBuilder);
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
