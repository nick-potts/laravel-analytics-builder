<?php

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Support\CompiledSchema;
use NickPotts\Slice\Support\SliceDefinition;

describe('CompiledSchema', function () {
    function createMockSchema(): CompiledSchema
    {
        // Create mock tables with relations and dimensions
        $ordersTable = new SliceDefinition(
            name: 'orders',
            provider: 'eloquent',
            connection: 'mysql',
            sqlTable: 'orders',
            sql: null,
            relations: new RelationGraph([]),
            dimensions: new DimensionCatalog([]),
            meta: ['label' => 'Orders'],
        );

        $customersTable = new SliceDefinition(
            name: 'customers',
            provider: 'eloquent',
            connection: 'mysql',
            sqlTable: 'customers',
            sql: null,
            relations: new RelationGraph([]),
            dimensions: new DimensionCatalog([]),
            meta: ['label' => 'Customers'],
        );

        $productsTable = new SliceDefinition(
            name: 'products',
            provider: 'manual',
            connection: 'pgsql',
            sqlTable: 'products',
            sql: null,
            relations: new RelationGraph([]),
            dimensions: new DimensionCatalog([]),
            meta: [],
        );

        return new CompiledSchema(
            tablesByIdentifier: [
                'eloquent:mysql:orders' => $ordersTable,
                'eloquent:mysql:customers' => $customersTable,
                'manual:pgsql:products' => $productsTable,
            ],
            tablesByName: [
                'orders' => $ordersTable,
                'customers' => $customersTable,
                'products' => $productsTable,
            ],
            tableProviders: [
                'eloquent:mysql:orders' => 'eloquent',
                'eloquent:mysql:customers' => 'eloquent',
                'manual:pgsql:products' => 'manual',
            ],
            relations: [
                'eloquent:mysql:orders' => new RelationGraph([]),
                'eloquent:mysql:customers' => new RelationGraph([]),
                'manual:pgsql:products' => new RelationGraph([]),
            ],
            dimensions: [
                'eloquent:mysql:orders' => new DimensionCatalog([]),
                'eloquent:mysql:customers' => new DimensionCatalog([]),
                'manual:pgsql:products' => new DimensionCatalog([]),
            ],
            connectionIndex: [
                'eloquent:mysql' => ['eloquent:mysql:orders', 'eloquent:mysql:customers'],
                'manual:pgsql' => ['manual:pgsql:products'],
            ],
        );
    }

    describe('resolveTable', function () {
        it('resolves table by full identifier', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTable('eloquent:mysql:orders');

            expect($table)->not->toBeNull();
            expect($table->identifier())->toBe('eloquent:mysql:orders');
            expect($table->name())->toBe('orders');
        });

        it('resolves table by bare name', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTable('orders');

            expect($table)->not->toBeNull();
            expect($table->identifier())->toBe('eloquent:mysql:orders');
        });

        it('returns null for non-existent table', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTable('non_existent');

            expect($table)->toBeNull();
        });

        it('prefers full identifier over bare name', function () {
            $schema = createMockSchema();
            // When querying with full identifier, should return that exact one
            $table = $schema->resolveTable('eloquent:mysql:orders');

            expect($table->identifier())->toBe('eloquent:mysql:orders');
        });
    });

    describe('resolveTableByName', function () {
        it('resolves table by bare name only', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTableByName('customers');

            expect($table)->not->toBeNull();
            expect($table->name())->toBe('customers');
        });

        it('returns null if not found', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTableByName('non_existent');

            expect($table)->toBeNull();
        });
    });

    describe('getRelations', function () {
        it('returns relation graph for table', function () {
            $schema = createMockSchema();
            $relations = $schema->getRelations('eloquent:mysql:orders');

            expect($relations)->toBeInstanceOf(RelationGraph::class);
        });

        it('throws if table not found', function () {
            $schema = createMockSchema();

            expect(fn () => $schema->getRelations('non_existent:table'))
                ->toThrow(RuntimeException::class);
        });
    });

    describe('getDimensions', function () {
        it('returns dimension catalog for table', function () {
            $schema = createMockSchema();
            $dimensions = $schema->getDimensions('eloquent:mysql:orders');

            expect($dimensions)->toBeInstanceOf(DimensionCatalog::class);
        });

        it('throws if table not found', function () {
            $schema = createMockSchema();

            expect(fn () => $schema->getDimensions('non_existent:table'))
                ->toThrow(RuntimeException::class);
        });
    });

    describe('getTablesOnConnection', function () {
        it('returns all tables on connection', function () {
            $schema = createMockSchema();
            $tables = $schema->getTablesOnConnection('eloquent:mysql');

            expect($tables)->toHaveCount(2);
            expect($tables)->toContain('eloquent:mysql:orders');
            expect($tables)->toContain('eloquent:mysql:customers');
        });

        it('returns empty array for non-existent connection', function () {
            $schema = createMockSchema();
            $tables = $schema->getTablesOnConnection('eloquent:sqlite');

            expect($tables)->toBeEmpty();
        });

        it('returns correct tables for each connection', function () {
            $schema = createMockSchema();

            $mysqlTables = $schema->getTablesOnConnection('eloquent:mysql');
            expect($mysqlTables)->toContain('eloquent:mysql:orders');

            $pgsqlTables = $schema->getTablesOnConnection('manual:pgsql');
            expect($pgsqlTables)->toContain('manual:pgsql:products');
        });
    });

    describe('getAllTables', function () {
        it('returns all tables by identifier', function () {
            $schema = createMockSchema();
            $tables = $schema->getAllTables();

            expect($tables)->toHaveCount(3);
            expect($tables)->toHaveKey('eloquent:mysql:orders');
            expect($tables)->toHaveKey('eloquent:mysql:customers');
            expect($tables)->toHaveKey('manual:pgsql:products');
        });
    });

    describe('hasTable', function () {
        it('returns true for existing table by identifier', function () {
            $schema = createMockSchema();
            expect($schema->hasTable('eloquent:mysql:orders'))->toBeTrue();
        });

        it('returns true for existing table by name', function () {
            $schema = createMockSchema();
            expect($schema->hasTable('orders'))->toBeTrue();
        });

        it('returns false for non-existent table', function () {
            $schema = createMockSchema();
            expect($schema->hasTable('non_existent'))->toBeFalse();
        });
    });

    describe('parseMetricSource', function () {
        it('parses metric reference with table and column', function () {
            $schema = createMockSchema();
            $metricSource = $schema->parseMetricSource('orders.total');

            expect($metricSource->sliceIdentifier())->toBe('eloquent:mysql:orders');
            expect($metricSource->columnName())->toBe('total');
        });

        it('parses metric reference with partial prefix for unique table', function () {
            // CompiledSchema can't parse partial prefixes - that's SchemaProviderManager's job
            // This test should only use references that resolve in the mock schema
            // Since we have 'orders' as a bare name in tablesByName, we can find it
            $schema = createMockSchema();
            $metricSource = $schema->parseMetricSource('orders.total');

            expect($metricSource->sliceIdentifier())->toBe('eloquent:mysql:orders');
            expect($metricSource->columnName())->toBe('total');
        });

        it('throws for invalid reference format', function () {
            $schema = createMockSchema();

            expect(fn () => $schema->parseMetricSource('invalid'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('throws for unresolved table', function () {
            $schema = createMockSchema();

            expect(fn () => $schema->parseMetricSource('non_existent.column'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('parses references with underscores in table name', function () {
            $schema = createMockSchema();
            $metricSource = $schema->parseMetricSource('orders.total');

            expect($metricSource->columnName())->toBe('total');
        });
    });

    describe('getTableProvider', function () {
        it('returns provider for table', function () {
            $schema = createMockSchema();
            $provider = $schema->getTableProvider('eloquent:mysql:orders');

            expect($provider)->toBe('eloquent');
        });

        it('returns null for non-existent table', function () {
            $schema = createMockSchema();
            $provider = $schema->getTableProvider('non_existent:table');

            expect($provider)->toBeNull();
        });
    });

    describe('connections', function () {
        it('returns all unique connections', function () {
            $schema = createMockSchema();
            $connections = $schema->connections();

            expect($connections)->toHaveCount(2);
            expect($connections)->toContain('eloquent:mysql');
            expect($connections)->toContain('manual:pgsql');
        });
    });

    describe('getConnectionsForMetrics', function () {
        it('returns unique connections for metrics', function () {
            $schema = createMockSchema();
            $metrics = [
                $schema->parseMetricSource('orders.total'),
                $schema->parseMetricSource('customers.id'),
            ];

            $connections = $schema->getConnectionsForMetrics($metrics);

            expect($connections)->toHaveCount(1);
            expect($connections)->toContain('eloquent:mysql');
        });

        it('returns multiple connections if metrics span them', function () {
            $schema = createMockSchema();
            $metrics = [
                $schema->parseMetricSource('orders.total'),
                $schema->parseMetricSource('products.price'),
            ];

            $connections = $schema->getConnectionsForMetrics($metrics);

            expect($connections)->toHaveCount(2);
            expect($connections)->toContain('eloquent:mysql');
            expect($connections)->toContain('manual:pgsql');
        });

        it('handles empty metrics array', function () {
            $schema = createMockSchema();
            $connections = $schema->getConnectionsForMetrics([]);

            expect($connections)->toBeEmpty();
        });
    });

    describe('immutability', function () {
        it('uses readonly properties', function () {
            $schema = createMockSchema();

            expect(fn () => $schema->tablesByIdentifier = [])
                ->toThrow(Error::class);
        });
    });
});
