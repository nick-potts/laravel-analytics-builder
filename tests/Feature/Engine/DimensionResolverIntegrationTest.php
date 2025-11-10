<?php

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\DimensionResolver;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Dimensions\StringDimension;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Relations\RelationType;

/**
 * Create a manually-defined table with dimensions and relations
 */
function createManualTable(string $name, array $dimensions = [], array $relations = []): SliceSource
{
    $relationGraph = new RelationGraph;
    foreach ($relations as $relationName => $descriptor) {
        $relationGraph = new RelationGraph(
            array_merge($relationGraph->all(), [$relationName => $descriptor])
        );
    }

    $catalog = new DimensionCatalog($dimensions);

    return new class($name, $relationGraph, $catalog) implements SliceSource
    {
        public function __construct(
            private string $tableName,
            private RelationGraph $relationGraph,
            private DimensionCatalog $dimensionCatalog,
        ) {}

        public function identifier(): string
        {
            return 'manual:'.$this->tableName;
        }

        public function name(): string
        {
            return $this->tableName;
        }

        public function provider(): string
        {
            return 'manual';
        }

        public function connection(): string
        {
            return 'manual:default';
        }

        public function relations(): RelationGraph
        {
            return $this->relationGraph;
        }

        public function dimensions(): DimensionCatalog
        {
            return $this->dimensionCatalog;
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
    $this->resolver = new DimensionResolver;
});

it('resolves single table dimension', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
        StringDimension::class => StringDimension::make('status'),
    ]);

    $timeDim = TimeDimension::make('created_at');
    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable]);

    expect($resolved)->toHaveCount(1);
    expect($resolved['orders']->column())->toBe('created_at');
});

it('resolves dimensions across multiple tables with relations', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
        StringDimension::class => StringDimension::make('status'),
    ], [
        'customer' => new RelationDescriptor(
            name: 'customer',
            type: RelationType::BelongsTo,
            targetTableIdentifier: 'manual:null:customers',
            keys: ['foreign' => 'customer_id', 'owner' => 'id'],
        ),
    ]);

    $customersTable = createManualTable('customers', [
        TimeDimension::class => TimeDimension::make('created_at'),
        StringDimension::class => StringDimension::make('country'),
    ]);

    // Resolve time dimension - should be in both tables
    $timeDim = TimeDimension::make('created_at');
    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable, $customersTable]);

    expect($resolved)->toHaveCount(2);
    expect($resolved)->toHaveKey('orders');
    expect($resolved)->toHaveKey('customers');
});

it('resolves table-specific dimensions', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
        StringDimension::class => StringDimension::make('status'),
    ]);

    $customersTable = createManualTable('customers', [
        StringDimension::class => StringDimension::make('country'),
    ]);

    // Status dimension only in orders
    $statusDim = StringDimension::make('status');
    $resolved = $this->resolver->resolveDimension($statusDim, [$ordersTable, $customersTable]);

    expect($resolved)->toHaveCount(1);
    expect($resolved)->toHaveKey('orders');
    expect($resolved)->not->toHaveKey('customers');
});

it('resolves shared dimensions across multiple tables', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    $productsTable = createManualTable('products', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    $invoicesTable = createManualTable('invoices', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    // All three tables have created_at dimension
    $timeDim = TimeDimension::make('created_at');
    $resolved = $this->resolver->resolveDimension($timeDim, [
        $ordersTable,
        $productsTable,
        $invoicesTable,
    ]);

    expect($resolved)->toHaveCount(3);
    expect(array_keys($resolved))->toContain('orders', 'products', 'invoices');
});

it('returns empty when dimension not in any table', function () {
    $ordersTable = createManualTable('orders', [
        StringDimension::class => StringDimension::make('status'),
    ]);

    $customersTable = createManualTable('customers', [
        StringDimension::class => StringDimension::make('country'),
    ]);

    // Try to resolve a dimension that doesn't exist
    $timeDim = TimeDimension::make('created_at');
    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable, $customersTable]);

    expect($resolved)->toBeEmpty();
});

it('extracts column names from resolved dimensions', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('order_date'),
        StringDimension::class => StringDimension::make('payment_method'),
    ]);

    $timeDim = TimeDimension::make('order_date');
    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable]);

    $column = $this->resolver->getColumnForTable($resolved['orders']);

    expect($column)->toBe('order_date');
});

it('handles time dimension with granularity settings', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    // Create time dimension with specific granularity
    $timeDim = TimeDimension::make('created_at')->daily();

    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable]);

    expect($resolved)->toHaveCount(1);
    expect($resolved['orders'])->toBeInstanceOf(TimeDimension::class);
    expect($resolved['orders']->granularity())->toBe('day');
});

it('resolves mixed table set with some having dimension and some not', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
        StringDimension::class => StringDimension::make('status'),
    ]);

    $productsTable = createManualTable('products', [
        StringDimension::class => StringDimension::make('category'),
    ]);

    $invoicesTable = createManualTable('invoices', [
        TimeDimension::class => TimeDimension::make('issued_at'),
    ]);

    // Resolve status - only in orders
    $statusDim = StringDimension::make('status');
    $resolved = $this->resolver->resolveDimension($statusDim, [
        $ordersTable,
        $productsTable,
        $invoicesTable,
    ]);

    expect($resolved)->toHaveCount(1);
    expect($resolved)->toHaveKey('orders');
});

it('validates granularity for time dimensions', function () {
    $ordersTable = createManualTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    $timeDim = TimeDimension::make('created_at')->hourly();
    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable]);

    // Validation should not throw (currently a stub)
    $this->resolver->validateGranularity($resolved, $timeDim);

    expect(true)->toBeTrue();
});
