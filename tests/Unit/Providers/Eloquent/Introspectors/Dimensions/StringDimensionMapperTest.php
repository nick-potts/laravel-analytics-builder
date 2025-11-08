<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\StringDimensionMapper;
use NickPotts\Slice\Schemas\Dimensions\StringDimension;

it('handles string cast type', function () {
    $mapper = new StringDimensionMapper();

    expect($mapper->handles())->toContain('string');
});

it('maps string cast to StringDimension', function () {
    $mapper = new StringDimensionMapper();

    $dimension = $mapper->map('country', 'string');

    expect($dimension)->toBeInstanceOf(StringDimension::class);
    expect($dimension->column())->toBe('country');
});

it('creates dimension with column name', function () {
    $mapper = new StringDimensionMapper();

    $dimension = $mapper->map('region', 'string');

    expect($dimension->column())->toBe('region');
    expect($dimension->name())->toBe('region');
});
