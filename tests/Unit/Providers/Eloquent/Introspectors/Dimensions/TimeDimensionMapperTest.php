<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\TimeDimensionMapper;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

it('handles datetime cast', function () {
    $mapper = new TimeDimensionMapper;

    expect($mapper->handles())->toContain('datetime');
    expect($mapper->handles())->toContain('immutable_datetime');
    expect($mapper->handles())->toContain('timestamp');
    expect($mapper->handles())->toContain('date');
});

it('maps datetime to timestamp precision', function () {
    $mapper = new TimeDimensionMapper;

    $dimension = $mapper->map('created_at', 'datetime');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->getPrecision())->toBe('timestamp');
});

it('maps timestamp to timestamp precision', function () {
    $mapper = new TimeDimensionMapper;

    $dimension = $mapper->map('published_at', 'timestamp');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->getPrecision())->toBe('timestamp');
});

it('maps date to date precision', function () {
    $mapper = new TimeDimensionMapper;

    $dimension = $mapper->map('birth_date', 'date');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->getPrecision())->toBe('date');
});

it('maps immutable_date to date precision', function () {
    $mapper = new TimeDimensionMapper;

    $dimension = $mapper->map('date_only', 'immutable_date');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->getPrecision())->toBe('date');
});

it('handles custom format datetime casts', function () {
    $mapper = new TimeDimensionMapper;

    $dimension = $mapper->map('custom_date', 'datetime:Y-m-d H:i:s');

    expect($dimension)->toBeInstanceOf(TimeDimension::class);
    expect($dimension->getPrecision())->toBe('timestamp');
});
