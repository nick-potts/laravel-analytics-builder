<?php

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Engine\DimensionResolver;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Dimensions\StringDimension;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * Create a mock table with dimensions
 */
function createDimensionResolverTestTable(string $name, array $dimensions = []): SliceSource
{
    $catalog = new DimensionCatalog($dimensions);

    return new class($name, $catalog) implements SliceSource
    {
        public function __construct(
            private string $tableName,
            private DimensionCatalog $dimensionCatalog,
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
            return 'mock:default';
        }

        public function relations(): RelationGraph
        {
            return new RelationGraph;
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

it('resolves time dimension from table catalog', function () {
    $timeDim = TimeDimension::make('created_at');
    $table = createDimensionResolverTestTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    $resolved = $this->resolver->resolveDimension($timeDim, [$table]);

    expect($resolved)->toHaveKey('orders');
    expect($resolved['orders'])->toBeInstanceOf(TimeDimension::class);
    expect($resolved['orders']->column())->toBe('created_at');
});

it('resolves string dimension from table catalog', function () {
    $stringDim = StringDimension::make('status');
    $table = createDimensionResolverTestTable('orders', [
        StringDimension::class => StringDimension::make('status'),
    ]);

    $resolved = $this->resolver->resolveDimension($stringDim, [$table]);

    expect($resolved)->toHaveKey('orders');
    expect($resolved['orders'])->toBeInstanceOf(StringDimension::class);
});

it('resolves across multiple tables', function () {
    $timeDim = TimeDimension::make('created_at');
    $ordersTable = createDimensionResolverTestTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);
    $customersTable = createDimensionResolverTestTable('customers', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable, $customersTable]);

    expect($resolved)->toHaveCount(2);
    expect($resolved)->toHaveKey('orders');
    expect($resolved)->toHaveKey('customers');
});

it('returns empty array when dimension not found', function () {
    $timeDim = TimeDimension::make('created_at');
    $table = createDimensionResolverTestTable('products'); // No dimensions

    $resolved = $this->resolver->resolveDimension($timeDim, [$table]);

    expect($resolved)->toBeEmpty();
});

it('returns empty array when dimension not matching in any table', function () {
    $timeDim = TimeDimension::make('created_at');
    $stringDim = StringDimension::make('status');
    $table = createDimensionResolverTestTable('orders', [
        $stringDim::class => $stringDim,
    ]);

    $resolved = $this->resolver->resolveDimension($timeDim, [$table]);

    expect($resolved)->toBeEmpty();
});

it('gets column name for dimension', function () {
    $timeDim = TimeDimension::make('created_at');

    $column = $this->resolver->getColumnForTable($timeDim);

    expect($column)->toBe('created_at');
});

it('gets column name for dimension with custom column', function () {
    $timeDim = TimeDimension::make('published_date');

    $column = $this->resolver->getColumnForTable($timeDim);

    expect($column)->toBe('published_date');
});

it('validates granularity for time dimensions', function () {
    $timeDim = TimeDimension::make('created_at')->daily();
    $ordersTable = createDimensionResolverTestTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);

    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable]);

    // Should not throw - currently a stub
    $this->resolver->validateGranularity($resolved, $timeDim);

    expect(true)->toBeTrue();
});

it('skips granularity validation for non-time dimensions', function () {
    $stringDim = StringDimension::make('status');
    $ordersTable = createDimensionResolverTestTable('orders', [
        StringDimension::class => StringDimension::make('status'),
    ]);

    $resolved = $this->resolver->resolveDimension($stringDim, [$ordersTable]);

    // Should not throw
    $this->resolver->validateGranularity($resolved, $stringDim);

    expect(true)->toBeTrue();
});

it('handles mixed tables with and without dimension', function () {
    $timeDim = TimeDimension::make('created_at');
    $ordersTable = createDimensionResolverTestTable('orders', [
        TimeDimension::class => TimeDimension::make('created_at'),
    ]);
    $productsTable = createDimensionResolverTestTable('products'); // No dimensions

    $resolved = $this->resolver->resolveDimension($timeDim, [$ordersTable, $productsTable]);

    expect($resolved)->toHaveCount(1);
    expect($resolved)->toHaveKey('orders');
    expect($resolved)->not->toHaveKey('products');
});
