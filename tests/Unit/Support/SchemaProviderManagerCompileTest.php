<?php

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Support\SchemaProviderManager;

describe('SchemaProviderManager::schema()', function () {
    function createMockProvider(string $name, array $tables)
    {
        $provider = \Mockery::mock(\NickPotts\Slice\Contracts\SchemaProvider::class);
        $provider->shouldReceive('name')->andReturn($name);
        $provider->shouldReceive('boot');
        $provider->shouldReceive('tables')->andReturn($tables);

        return $provider;
    }

    function createMockTable(
        string $identifier,
        string $name,
        string $provider,
        string $connection,
        ?RelationGraph $relations = null,
        ?DimensionCatalog $dimensions = null,
    ) {
        $table = \Mockery::mock(\NickPotts\Slice\Contracts\SliceSource::class);
        $table->shouldReceive('identifier')->andReturn($identifier);
        $table->shouldReceive('name')->andReturn($name);
        $table->shouldReceive('provider')->andReturn($provider);
        $table->shouldReceive('connection')->andReturn($connection);
        $table->shouldReceive('sqlTable')->andReturn($name);
        $table->shouldReceive('sql')->andReturn(null);
        $table->shouldReceive('relations')->andReturn($relations ?? new RelationGraph([]));
        $table->shouldReceive('dimensions')->andReturn($dimensions ?? new DimensionCatalog([]));
        $table->shouldReceive('meta')->andReturn([]);

        return $table;
    }

    it('compiles schema from single provider', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $customersTable = createMockTable('eloquent:customers', 'customers', 'eloquent', 'eloquent:mysql');

        $provider = createMockProvider('eloquent', [$ordersTable, $customersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        expect($schema->hasTable('eloquent:orders'))->toBeTrue();
        expect($schema->hasTable('eloquent:customers'))->toBeTrue();
        expect($schema->hasTable('orders'))->toBeTrue();
        expect($schema->hasTable('customers'))->toBeTrue();
    });

    it('compiles schema from multiple providers', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $eventsTable = createMockTable('manual:events', 'events', 'manual', 'manual:pgsql');

        $eloquentProvider = createMockProvider('eloquent', [$ordersTable]);
        $manualProvider = createMockProvider('manual', [$eventsTable]);

        $manager->register($eloquentProvider);
        $manager->register($manualProvider);

        $schema = $manager->schema();

        expect($schema->hasTable('eloquent:orders'))->toBeTrue();
        expect($schema->hasTable('manual:events'))->toBeTrue();
        expect($schema->hasTable('orders'))->toBeTrue();
        expect($schema->hasTable('events'))->toBeTrue();
    });

    it('handles ambiguous table names by removing bare name', function () {
        $manager = new SchemaProviderManager;

        $eloquentOrders = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $manualOrders = createMockTable('manual:orders', 'orders', 'manual', 'manual:pgsql');

        $eloquentProvider = createMockProvider('eloquent', [$eloquentOrders]);
        $manualProvider = createMockProvider('manual', [$manualOrders]);

        $manager->register($eloquentProvider);
        $manager->register($manualProvider);

        $schema = $manager->schema();

        // Both prefixed versions should exist
        expect($schema->hasTable('eloquent:orders'))->toBeTrue();
        expect($schema->hasTable('manual:orders'))->toBeTrue();

        // Bare name should be removed (ambiguous)
        expect($schema->hasTable('orders'))->toBeFalse();
    });

    it('prefers first provider for bare name in non-ambiguous case', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');

        $provider = createMockProvider('eloquent', [$ordersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        // Bare name should resolve to the only provider
        $table = $schema->resolveTable('orders');
        expect($table->identifier())->toBe('eloquent:orders');
    });

    it('pre-computes relation graphs', function () {
        $manager = new SchemaProviderManager;

        $relationGraph = new RelationGraph([]);
        $ordersTable = createMockTable(
            'eloquent:orders',
            'orders',
            'eloquent',
            'eloquent:mysql',
            relations: $relationGraph
        );

        $provider = createMockProvider('eloquent', [$ordersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        // Relations should be pre-fetched and available
        $relations = $schema->getRelations('eloquent:orders');
        expect($relations)->toBe($relationGraph);
    });

    it('pre-computes dimension catalogs', function () {
        $manager = new SchemaProviderManager;

        $dimensionCatalog = new DimensionCatalog([]);
        $ordersTable = createMockTable(
            'eloquent:orders',
            'orders',
            'eloquent',
            'eloquent:mysql',
            dimensions: $dimensionCatalog
        );

        $provider = createMockProvider('eloquent', [$ordersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        // Dimensions should be pre-fetched and available
        $dimensions = $schema->getDimensions('eloquent:orders');
        expect($dimensions)->toBe($dimensionCatalog);
    });

    it('builds connection index correctly', function () {
        $manager = new SchemaProviderManager;

        $mysqlOrder = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $mysqlCustomer = createMockTable('eloquent:customers', 'customers', 'eloquent', 'eloquent:mysql');
        $pgsqlEvent = createMockTable('manual:events', 'events', 'manual', 'manual:pgsql');

        $eloquentProvider = createMockProvider('eloquent', [$mysqlOrder, $mysqlCustomer]);
        $manualProvider = createMockProvider('manual', [$pgsqlEvent]);

        $manager->register($eloquentProvider);
        $manager->register($manualProvider);

        $schema = $manager->schema();

        // MySQL connection should have 2 tables
        $mysqlTables = $schema->getTablesOnConnection('eloquent:mysql');
        expect($mysqlTables)->toHaveCount(2);
        expect($mysqlTables)->toContain('eloquent:orders');
        expect($mysqlTables)->toContain('eloquent:customers');

        // PostgreSQL connection should have 1 table
        $pgsqlTables = $schema->getTablesOnConnection('manual:pgsql');
        expect($pgsqlTables)->toHaveCount(1);
        expect($pgsqlTables)->toContain('manual:events');
    });

    it('memoizes compilation result', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema1 = $manager->schema();
        $schema2 = $manager->schema();

        // Should return same instance
        expect($schema1)->toBe($schema2);
    });

    it('can clear compiled schema', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema1 = $manager->schema();
        $manager->clearCompiled();
        $schema2 = $manager->schema();

        // Should return different instances after clear
        expect($schema1)->not->toBe($schema2);
    });

    it('handles empty providers', function () {
        $manager = new SchemaProviderManager;

        $schema = $manager->schema();

        expect($schema->getAllTables())->toBeEmpty();
        expect($schema->connections())->toBeEmpty();
    });

    it('includes all table metadata in compiled schema', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema = $manager->schema();
        $compiledTable = $schema->resolveTable('eloquent:orders');

        // Should have preserved all metadata
        expect($compiledTable->identifier())->toBe('eloquent:orders');
        expect($compiledTable->name())->toBe('orders');
        expect($compiledTable->provider())->toBe('eloquent');
        expect($compiledTable->connection())->toBe('eloquent:mysql');
        expect($compiledTable->sqlTable())->toBe('orders');
    });

    it('parses metric sources using compiled schema', function () {
        $manager = new SchemaProviderManager;

        $ordersTable = createMockTable('eloquent:orders', 'orders', 'eloquent', 'eloquent:mysql');
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema = $manager->schema();
        $metricSource = $schema->parseMetricSource('orders.total');

        expect($metricSource->sliceIdentifier())->toBe('eloquent:orders');
        expect($metricSource->columnName())->toBe('total');
    });
});
