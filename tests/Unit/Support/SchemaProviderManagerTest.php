<?php

namespace NickPotts\Slice\Tests\Unit\Support;

use NickPotts\Slice\Exceptions\TableNotFoundException;
use NickPotts\Slice\Support\AmbiguousTableException;
use NickPotts\Slice\Support\SchemaProviderManager;
use NickPotts\Slice\Tests\Support\MockSchemaProvider;
use NickPotts\Slice\Tests\Support\MockTableContract;

it('registers providers', function () {
    $manager = new SchemaProviderManager;
    $provider = new MockSchemaProvider;
    $provider->setName('test');

    $manager->register($provider);

    expect($manager->getProvider('test'))->toBe($provider);
});

it('resolves unambiguous table', function () {
    $manager = new SchemaProviderManager;
    $table = new MockTableContract('orders');

    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('eloquent');

    $manager->register($provider);

    expect($manager->resolve('orders'))->toBe($table);
});

it('throws when table not found', function () {
    $manager = new SchemaProviderManager;
    $provider = new MockSchemaProvider;
    $provider->setName('eloquent');
    $manager->register($provider);

    $manager->resolve('nonexistent');
})->throws(TableNotFoundException::class);

it('throws when table is ambiguous', function () {
    $manager = new SchemaProviderManager;

    $table1 = new MockTableContract('orders');
    $provider1 = new MockSchemaProvider;
    $provider1->registerTable($table1)->setName('eloquent');

    $table2 = new MockTableContract('orders');
    $provider2 = new MockSchemaProvider;
    $provider2->registerTable($table2)->setName('clickhouse');

    $manager->register($provider1);
    $manager->register($provider2);

    $manager->resolve('orders');
})->throws(AmbiguousTableException::class);

it('resolves with provider prefix when ambiguous', function () {
    $manager = new SchemaProviderManager;

    $table1 = new MockTableContract('orders');
    $provider1 = new MockSchemaProvider;
    $provider1->registerTable($table1)->setName('eloquent');

    $table2 = new MockTableContract('orders');
    $provider2 = new MockSchemaProvider;
    $provider2->registerTable($table2)->setName('clickhouse');

    $manager->register($provider1);
    $manager->register($provider2);

    $source = $manager->parseMetricSource('eloquent:orders.total');
    expect($source->table)->toBe($table1);
    expect($source->columnName())->toBe('total');
});

it('parses metric source table.column', function () {
    $manager = new SchemaProviderManager;
    $table = new MockTableContract('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('eloquent');
    $manager->register($provider);

    $source = $manager->parseMetricSource('orders.total');
    expect($source->tableName())->toBe('orders');
    expect($source->columnName())->toBe('total');
});

it('parses metric source with provider prefix', function () {
    $manager = new SchemaProviderManager;
    $table = new MockTableContract('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('clickhouse');
    $manager->register($provider);

    $source = $manager->parseMetricSource('clickhouse:orders.total');
    expect($source->tableName())->toBe('orders');
    expect($source->columnName())->toBe('total');
});

it('detects provider prefix vs connection prefix', function () {
    $manager = new SchemaProviderManager;
    $table = new MockTableContract('orders', 'mysql');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('eloquent');
    $manager->register($provider);

    // 'analytics' is not a provider, so it's a connection prefix
    $source = $manager->parseMetricSource('analytics:orders.total');
    expect($source->getConnection())->toBe('analytics');
    expect($source->tableName())->toBe('orders');
});

it('gets all tables', function () {
    $manager = new SchemaProviderManager;
    $table1 = new MockTableContract('orders');
    $table2 = new MockTableContract('customers');

    $provider = new MockSchemaProvider;
    $provider->registerTable($table1);
    $provider->registerTable($table2)->setName('eloquent');

    $manager->register($provider);

    $allTables = $manager->allTables();
    expect($allTables)->toHaveKey('orders');
    expect($allTables)->toHaveKey('customers');
});

it('prefixes ambiguous table names in allTables', function () {
    $manager = new SchemaProviderManager;

    $table1 = new MockTableContract('orders');
    $provider1 = new MockSchemaProvider;
    $provider1->registerTable($table1)->setName('eloquent');

    $table2 = new MockTableContract('orders');
    $provider2 = new MockSchemaProvider;
    $provider2->registerTable($table2)->setName('clickhouse');

    $manager->register($provider1);
    $manager->register($provider2);

    $allTables = $manager->allTables();
    expect($allTables)->toHaveKey('eloquent:orders');
    expect($allTables)->toHaveKey('clickhouse:orders');
});

it('checks if table can be resolved', function () {
    $manager = new SchemaProviderManager;
    $table = new MockTableContract('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('eloquent');
    $manager->register($provider);

    expect($manager->canResolve('orders'))->toBeTrue();
    expect($manager->canResolve('nonexistent'))->toBeFalse();
});

it('gets available tables', function () {
    $manager = new SchemaProviderManager;
    $table = new MockTableContract('orders');
    $provider = new MockSchemaProvider;
    $provider->registerTable($table)->setName('eloquent');
    $manager->register($provider);

    $available = $manager->getAvailableTables();
    expect($available)->toContain('orders');
});
