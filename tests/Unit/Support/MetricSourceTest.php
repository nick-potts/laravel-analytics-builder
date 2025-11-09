<?php

namespace NickPotts\Slice\Tests\Unit\Support;

use NickPotts\Slice\Support\MetricSource;
use NickPotts\Slice\Tests\Support\MockTableContract;

it('stores table and column', function () {
    $table = new MockTableContract('orders');
    $source = new MetricSource($table, 'total');
    expect($source->table)->toBe($table);
    expect($source->column)->toBe('total');
});

it('stores connection', function () {
    $table = new MockTableContract('orders');
    $source = new MetricSource($table, 'total', 'mysql');
    expect($source->connection)->toBe('mysql');
});

it('generates metric key', function () {
    $table = new MockTableContract('orders');
    $source = new MetricSource($table, 'total');
    expect($source->key())->toBe('orders.total');
});

it('gets table name', function () {
    $table = new MockTableContract('orders');
    $source = new MetricSource($table, 'total');
    expect($source->tableName())->toBe('orders');
});

it('gets column name', function () {
    $table = new MockTableContract('orders');
    $source = new MetricSource($table, 'total');
    expect($source->columnName())->toBe('total');
});

it('returns explicit connection', function () {
    $table = new MockTableContract('orders', 'default');
    $source = new MetricSource($table, 'total', 'override');
    expect($source->getConnection())->toBe('override');
});

it('falls back to table connection', function () {
    $table = new MockTableContract('orders', 'mysql');
    $source = new MetricSource($table, 'total');
    expect($source->getConnection())->toBe('eloquent:mysql');
});
