<?php

use NickPotts\Slice\Support\SchemaProviderManager;
use NickPotts\Slice\Support\SliceDefinition;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Contracts\SchemaProvider;
use NickPotts\Slice\Support\Cache\SchemaCache;
use NickPotts\Slice\Support\MetricSource;
use NickPotts\Slice\Tests\Factories\MockTableFactory;

describe('SchemaProviderManager::schema()', function () {
    function createMockProvider(string $name, array $tables): SchemaProvider
    {
        return new class($name, $tables) implements SchemaProvider
        {
            public function __construct(
                private string $providerName,
                private array $sourceTables,
            ) {}

            public function boot(SchemaCache $cache): void
            {
                // No-op for tests
            }

            public function tables(): iterable
            {
                return $this->sourceTables;
            }

            public function provides(string $identifier): bool
            {
                foreach ($this->sourceTables as $table) {
                    if ($table->identifier() === $identifier || $table->name() === $identifier) {
                        return true;
                    }
                }
                return false;
            }

            public function resolveMetricSource(string $reference): MetricSource
            {
                throw new \Exception('Not implemented for tests');
            }

            public function relations(string $table): RelationGraph
            {
                foreach ($this->sourceTables as $source) {
                    if ($source->name() === $table) {
                        return $source->relations();
                    }
                }
                return new RelationGraph([]);
            }

            public function dimensions(string $table): DimensionCatalog
            {
                foreach ($this->sourceTables as $source) {
                    if ($source->name() === $table) {
                        return $source->dimensions();
                    }
                }
                return new DimensionCatalog([]);
            }

            public function name(): string
            {
                return $this->providerName;
            }
        };
    }

    it('compiles schema from single provider', function () {
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $customersTable = MockTableFactory::create('customers')->provider('eloquent')->connection('mysql')->build();

        $provider = createMockProvider('eloquent', [$ordersTable, $customersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        expect($schema->hasTable('eloquent:orders'))->toBeTrue();
        expect($schema->hasTable('eloquent:customers'))->toBeTrue();
        expect($schema->hasTable('orders'))->toBeTrue();
        expect($schema->hasTable('customers'))->toBeTrue();
    });

    it('compiles schema from multiple providers', function () {
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $eventsTable = MockTableFactory::create('events')->provider('manual')->connection('pgsql')->build();

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
        $manager = new SchemaProviderManager();

        $eloquentOrders = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $manualOrders = MockTableFactory::create('orders')->provider('manual')->connection('pgsql')->build();

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
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();

        $provider = createMockProvider('eloquent', [$ordersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        // Bare name should resolve to the only provider
        $table = $schema->resolveTable('orders');
        expect($table->identifier())->toBe('eloquent:mysql:orders');
    });

    it('pre-computes relation graphs', function () {
        $manager = new SchemaProviderManager();

        $relationGraph = new RelationGraph([]);
        $ordersTable = MockTableFactory::create('orders')
            ->provider('eloquent')
            ->connection('mysql')
            ->relationGraph($relationGraph)
            ->build();

        $provider = createMockProvider('eloquent', [$ordersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        // Relations should be pre-fetched and available
        $relations = $schema->getRelations('eloquent:orders');
        expect($relations)->toBe($relationGraph);
    });

    it('pre-computes dimension catalogs', function () {
        $manager = new SchemaProviderManager();

        $dimensionCatalog = new DimensionCatalog([]);
        $ordersTable = MockTableFactory::create('orders')
            ->provider('eloquent')
            ->connection('mysql')
            ->dimensions($dimensionCatalog)
            ->build();

        $provider = createMockProvider('eloquent', [$ordersTable]);
        $manager->register($provider);

        $schema = $manager->schema();

        // Dimensions should be pre-fetched and available
        $dimensions = $schema->getDimensions('eloquent:orders');
        expect($dimensions)->toBe($dimensionCatalog);
    });

    it('builds connection index correctly', function () {
        $manager = new SchemaProviderManager();

        $mysqlOrder = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $mysqlCustomer = MockTableFactory::create('customers')->provider('eloquent')->connection('mysql')->build();
        $pgsqlEvent = MockTableFactory::create('events')->provider('manual')->connection('pgsql')->build();

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
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema1 = $manager->schema();
        $schema2 = $manager->schema();

        // Should return same instance
        expect($schema1)->toBe($schema2);
    });

    it('can clear compiled schema', function () {
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema1 = $manager->schema();
        $manager->clearCompiled();
        $schema2 = $manager->schema();

        // Should return different instances after clear
        expect($schema1)->not->toBe($schema2);
    });

    it('handles empty providers', function () {
        $manager = new SchemaProviderManager();

        $schema = $manager->schema();

        expect($schema->getAllTables())->toBeEmpty();
        expect($schema->connections())->toBeEmpty();
    });

    it('includes all table metadata in compiled schema', function () {
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema = $manager->schema();
        $compiledTable = $schema->resolveTable('eloquent:mysql:orders');

        // Should have preserved all metadata
        expect($compiledTable->identifier())->toBe('eloquent:mysql:orders');
        expect($compiledTable->name())->toBe('orders');
        expect($compiledTable->provider())->toBe('eloquent');
        expect($compiledTable->connection())->toBe('mysql');
        expect($compiledTable->sqlTable())->toBe('orders');
    });

    it('parses metric sources using compiled schema', function () {
        $manager = new SchemaProviderManager();

        $ordersTable = MockTableFactory::create('orders')->provider('eloquent')->connection('mysql')->build();
        $provider = createMockProvider('eloquent', [$ordersTable]);

        $manager->register($provider);

        $schema = $manager->schema();
        $metricSource = $schema->parseMetricSource('orders.total');

        expect($metricSource->sliceIdentifier())->toBe('eloquent:mysql:orders');
        expect($metricSource->columnName())->toBe('total');
    });
});
