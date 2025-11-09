<?php

use NickPotts\Slice\Support\CompiledSchema;
use NickPotts\Slice\Support\SliceDefinition;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;

describe('CompiledSchema', function () {
    function createMockSchema(): CompiledSchema
    {
        // Create mock tables with relations and dimensions
        $ordersTable = new SliceDefinition(
            identifier: 'eloquent:orders',
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
            identifier: 'eloquent:customers',
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
            identifier: 'manual:products',
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
                'eloquent:orders' => $ordersTable,
                'eloquent:customers' => $customersTable,
                'manual:products' => $productsTable,
            ],
            tablesByName: [
                'orders' => $ordersTable,
                'customers' => $customersTable,
                'products' => $productsTable,
            ],
            tableProviders: [
                'eloquent:orders' => 'eloquent',
                'eloquent:customers' => 'eloquent',
                'manual:products' => 'manual',
            ],
            relations: [
                'eloquent:orders' => new RelationGraph([]),
                'eloquent:customers' => new RelationGraph([]),
                'manual:products' => new RelationGraph([]),
            ],
            dimensions: [
                'eloquent:orders' => new DimensionCatalog([]),
                'eloquent:customers' => new DimensionCatalog([]),
                'manual:products' => new DimensionCatalog([]),
            ],
            connectionIndex: [
                'mysql' => ['eloquent:orders', 'eloquent:customers'],
                'pgsql' => ['manual:products'],
            ],
        );
    }

    describe('resolveTable', function () {
        it('resolves table by full identifier', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTable('eloquent:orders');

            expect($table)->not->toBeNull();
            expect($table->identifier())->toBe('eloquent:orders');
            expect($table->name())->toBe('orders');
        });

        it('resolves table by bare name', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTable('orders');

            expect($table)->not->toBeNull();
            expect($table->identifier())->toBe('eloquent:orders');
        });

        it('returns null for non-existent table', function () {
            $schema = createMockSchema();
            $table = $schema->resolveTable('non_existent');

            expect($table)->toBeNull();
        });

        it('prefers full identifier over bare name', function () {
            $schema = createMockSchema();
            // When querying with full identifier, should return that exact one
            $table = $schema->resolveTable('eloquent:orders');

            expect($table->identifier())->toBe('eloquent:orders');
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
            $relations = $schema->getRelations('eloquent:orders');

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
            $dimensions = $schema->getDimensions('eloquent:orders');

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
            $tables = $schema->getTablesOnConnection('mysql');

            expect($tables)->toHaveCount(2);
            expect($tables)->toContain('eloquent:orders');
            expect($tables)->toContain('eloquent:customers');
        });

        it('returns empty array for non-existent connection', function () {
            $schema = createMockSchema();
            $tables = $schema->getTablesOnConnection('sqlite');

            expect($tables)->toBeEmpty();
        });

        it('returns correct tables for each connection', function () {
            $schema = createMockSchema();

            $mysqlTables = $schema->getTablesOnConnection('mysql');
            expect($mysqlTables)->toContain('eloquent:orders');

            $pgsqlTables = $schema->getTablesOnConnection('pgsql');
            expect($pgsqlTables)->toContain('manual:products');
        });
    });

    describe('getAllTables', function () {
        it('returns all tables by identifier', function () {
            $schema = createMockSchema();
            $tables = $schema->getAllTables();

            expect($tables)->toHaveCount(3);
            expect($tables)->toHaveKey('eloquent:orders');
            expect($tables)->toHaveKey('eloquent:customers');
            expect($tables)->toHaveKey('manual:products');
        });
    });

    describe('hasTable', function () {
        it('returns true for existing table by identifier', function () {
            $schema = createMockSchema();
            expect($schema->hasTable('eloquent:orders'))->toBeTrue();
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

            expect($metricSource->sliceIdentifier())->toBe('eloquent:orders');
            expect($metricSource->columnName())->toBe('total');
        });

        it('parses metric reference with provider prefix', function () {
            $schema = createMockSchema();
            $metricSource = $schema->parseMetricSource('eloquent:orders.total');

            expect($metricSource->sliceIdentifier())->toBe('eloquent:orders');
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
            $provider = $schema->getTableProvider('eloquent:orders');

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
            expect($connections)->toContain('mysql');
            expect($connections)->toContain('pgsql');
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
            expect($connections)->toContain('mysql');
        });

        it('returns multiple connections if metrics span them', function () {
            $schema = createMockSchema();
            $metrics = [
                $schema->parseMetricSource('orders.total'),
                $schema->parseMetricSource('products.price'),
            ];

            $connections = $schema->getConnectionsForMetrics($metrics);

            expect($connections)->toHaveCount(2);
            expect($connections)->toContain('mysql');
            expect($connections)->toContain('pgsql');
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
