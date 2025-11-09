<?php

namespace NickPotts\Slice\Tests\Unit\Schemas\Dimensions;

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

it('creates empty dimension catalog', function () {
    $catalog = new DimensionCatalog;
    expect($catalog->isEmpty())->toBeTrue();
    expect($catalog->count())->toBe(0);
});

it('checks dimension existence', function () {
    $mock = TimeDimension::make('created_at');
    $catalog = new DimensionCatalog(['time' => $mock]);
    expect($catalog->has('time'))->toBeTrue();
    expect($catalog->has('missing'))->toBeFalse();
});

it('retrieves dimension by key', function () {
    $mock = TimeDimension::make('created_at');
    $catalog = new DimensionCatalog(['time' => $mock]);
    expect($catalog->get('time'))->toBe($mock);
    expect($catalog->get('missing'))->toBeNull();
});

it('gets all dimensions', function () {
    $mock1 = TimeDimension::make('created_at');
    $mock2 = TimeDimension::make('updated_at');
    $dimensions = ['time' => $mock1, 'country' => $mock2];
    $catalog = new DimensionCatalog($dimensions);
    expect($catalog->all())->toBe($dimensions);
});

it('gets all dimension keys', function () {
    $mock1 = TimeDimension::make('created_at');
    $mock2 = TimeDimension::make('updated_at');
    $catalog = new DimensionCatalog(['time' => $mock1, 'country' => $mock2]);
    expect($catalog->keys())->toBe(['time', 'country']);
});

it('counts dimensions', function () {
    $mock1 = TimeDimension::make('created_at');
    $mock2 = TimeDimension::make('updated_at');
    $catalog = new DimensionCatalog(['time' => $mock1, 'country' => $mock2]);
    expect($catalog->count())->toBe(2);
});

it('iterates over dimensions', function () {
    $mock1 = TimeDimension::make('created_at');
    $mock2 = TimeDimension::make('updated_at');
    $catalog = new DimensionCatalog(['time' => $mock1, 'country' => $mock2]);
    $keys = [];
    $catalog->forEach(function ($key) use (&$keys) {
        $keys[] = $key;
    });
    expect($keys)->toBe(['time', 'country']);
});

it('adds dimension to catalog', function () {
    $mock = TimeDimension::make('created_at');
    $catalog = new DimensionCatalog;
    $catalog->add('time', $mock);
    expect($catalog->has('time'))->toBeTrue();
    expect($catalog->count())->toBe(1);
});

it('merges catalogs', function () {
    $mock1 = TimeDimension::make('created_at');
    $mock2 = TimeDimension::make('updated_at');
    $catalog1 = new DimensionCatalog(['time' => $mock1]);
    $catalog2 = new DimensionCatalog(['country' => $mock2]);
    $merged = $catalog1->merge($catalog2);
    expect($merged->count())->toBe(2);
    expect($merged->has('time'))->toBeTrue();
    expect($merged->has('country'))->toBeTrue();
});
