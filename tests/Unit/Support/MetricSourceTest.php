<?php

namespace NickPotts\Slice\Tests\Unit\Support;

use NickPotts\Slice\Support\MetricSource;
use NickPotts\Slice\Tests\Support\MockSliceSource;

it('stores table and column', function () {
    $table = new MockSliceSource('orders');
    $source = new MetricSource($table, 'total');
    expect($source->tableName())->toBe('orders');
    expect($source->column)->toBe('total');
});

it('generates metric key', function () {
    $table = new MockSliceSource('orders');
    $source = new MetricSource($table, 'total');
    expect($source->key())->toBe('eloquent:orders.total');
});

it('gets table name', function () {
    $table = new MockSliceSource('orders');
    $source = new MetricSource($table, 'total');
    expect($source->tableName())->toBe('orders');
});

it('gets column name', function () {
    $table = new MockSliceSource('orders');
    $source = new MetricSource($table, 'total');
    expect($source->columnName())->toBe('total');
});

it('gets connection from table', function () {
    $table = new MockSliceSource('orders', 'eloquent:mysql');
    $source = new MetricSource($table, 'total');
    expect($source->slice->connection())->toBe('eloquent:mysql');
});
