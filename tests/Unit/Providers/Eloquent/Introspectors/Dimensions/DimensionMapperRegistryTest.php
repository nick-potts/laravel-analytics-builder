<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\BooleanDimensionMapper;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\DimensionMapperRegistry;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\StringDimensionMapper;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\TimeDimensionMapper;
use NickPotts\Slice\Schemas\Dimensions\BooleanDimension;
use NickPotts\Slice\Schemas\Dimensions\StringDimension;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

it('registers built-in mappers on construction', function () {
    $registry = new DimensionMapperRegistry;

    expect($registry->getMapper('datetime'))->toBeInstanceOf(TimeDimensionMapper::class);
    expect($registry->getMapper('boolean'))->toBeInstanceOf(BooleanDimensionMapper::class);
    expect($registry->getMapper('string'))->toBeInstanceOf(StringDimensionMapper::class);
});

it('maps datetime cast to TimeDimension', function () {
    $registry = new DimensionMapperRegistry;
    $mapper = $registry->getMapper('datetime');

    $dimension = $mapper->map('created_at', 'datetime');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->column())->toBe('created_at');
    expect($dimension->getPrecision())->toBe('timestamp');
});

it('maps date cast to TimeDimension with date precision', function () {
    $registry = new DimensionMapperRegistry;
    $mapper = $registry->getMapper('date');

    $dimension = $mapper->map('birth_date', 'date');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->getPrecision())->toBe('date');
});

it('maps boolean cast to BooleanDimension', function () {
    $registry = new DimensionMapperRegistry;
    $mapper = $registry->getMapper('boolean');

    $dimension = $mapper->map('is_active', 'boolean');

    expect($dimension)->toBeInstanceOf(BooleanDimension::class);
    expect($dimension->column())->toBe('is_active');
});

it('maps string cast to StringDimension', function () {
    $registry = new DimensionMapperRegistry;
    $mapper = $registry->getMapper('string');

    $dimension = $mapper->map('country', 'string');

    expect($dimension)->toBeInstanceOf(StringDimension::class);
    expect($dimension->column())->toBe('country');
});

it('handles custom format casts', function () {
    $registry = new DimensionMapperRegistry;

    // Custom datetime format should match 'datetime' mapper
    $dimension = $registry->getMapper('datetime:Y-m-d H:i:s')
        ?->map('date_time', 'datetime:Y-m-d H:i:s');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
});

it('throws on conflicting handlers', function () {
    $registry = new DimensionMapperRegistry;

    // Trying to register a mapper that handles a type already handled should throw
    expect(fn () => $registry->register(new StringDimensionMapper))
        ->toThrow(RuntimeException::class);
});
