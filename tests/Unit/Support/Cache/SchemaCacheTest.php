<?php

namespace NickPotts\Slice\Tests\Unit\Support\Cache;

use NickPotts\Slice\Support\Cache\SchemaCache;

it('stores and retrieves data', function () {
    $cache = new SchemaCache;
    $cache->put('key', ['data' => 'value']);
    expect($cache->get('key'))->toBe(['data' => 'value']);
});

it('checks key existence', function () {
    $cache = new SchemaCache;
    $cache->put('key', ['data']);
    expect($cache->has('key'))->toBeTrue();
    expect($cache->has('missing'))->toBeFalse();
});

it('forgets specific key', function () {
    $cache = new SchemaCache;
    $cache->put('key', ['data']);
    $cache->forget('key');
    expect($cache->has('key'))->toBeFalse();
});

it('flushes all cache', function () {
    $cache = new SchemaCache;
    $cache->put('key1', ['data1']);
    $cache->put('key2', ['data2']);
    $cache->flush();
    expect($cache->has('key1'))->toBeFalse();
    expect($cache->has('key2'))->toBeFalse();
});

it('disables caching', function () {
    $cache = new SchemaCache;
    $cache->put('key', ['data']);
    $cache->disable();
    expect($cache->get('key'))->toBeNull();
    expect($cache->isEnabled())->toBeFalse();
});

it('re-enables caching', function () {
    $cache = new SchemaCache;
    $cache->disable();
    $cache->enable();
    $cache->put('key', ['data']);
    expect($cache->get('key'))->toBe(['data']);
    expect($cache->isEnabled())->toBeTrue();
});

it('returns all cached data', function () {
    $cache = new SchemaCache;
    $cache->put('key1', ['data1']);
    $cache->put('key2', ['data2']);
    $all = $cache->all();
    expect($all)->toHaveKey('key1');
    expect($all)->toHaveKey('key2');
});

it('counts cache size', function () {
    $cache = new SchemaCache;
    $cache->put('key1', ['data1']);
    $cache->put('key2', ['data2']);
    expect($cache->size())->toBe(2);
});
