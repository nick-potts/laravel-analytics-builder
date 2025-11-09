<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\BooleanDimensionMapper;
use NickPotts\Slice\Schemas\Dimensions\BooleanDimension;

it('handles boolean cast types', function () {
    $mapper = new BooleanDimensionMapper;

    expect($mapper->handles())->toContain('bool');
    expect($mapper->handles())->toContain('boolean');
});

it('maps boolean cast to BooleanDimension', function () {
    $mapper = new BooleanDimensionMapper;

    $dimension = $mapper->map('is_active', 'boolean');

    expect($dimension)->toBeInstanceOf(BooleanDimension::class);
    expect($dimension->column())->toBe('is_active');
});

it('maps bool cast to BooleanDimension', function () {
    $mapper = new BooleanDimensionMapper;

    $dimension = $mapper->map('is_premium', 'bool');

    expect($dimension)->toBeInstanceOf(BooleanDimension::class);
    expect($dimension->column())->toBe('is_premium');
});
