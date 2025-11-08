<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\EnumDimensionMapper;
use NickPotts\Slice\Schemas\Dimensions\EnumDimension;
use NickPotts\Slice\Tests\Support\TestEnum;

it('supports enum classes', function () {
    $mapper = new EnumDimensionMapper;

    expect($mapper->supportsEnum(TestEnum::class))->toBeTrue();
    expect($mapper->supportsEnum('stdClass'))->toBeFalse();
    expect($mapper->supportsEnum('NonExistentClass'))->toBeFalse();
});

it('maps enum class to EnumDimension', function () {
    $mapper = new EnumDimensionMapper;

    $dimension = $mapper->map('status', TestEnum::class);

    expect($dimension)->toBeInstanceOf(EnumDimension::class);
    expect($dimension->column())->toBe('status');
    expect($dimension->enumClass())->toBe(TestEnum::class);
    expect($dimension->cases())->not->toBeEmpty();
});

it('extracts enum cases', function () {
    $mapper = new EnumDimensionMapper;

    $dimension = $mapper->map('status', TestEnum::class);

    $cases = $dimension->cases();
    expect($cases)->toHaveCount(3);

    // Cases are enum instances
    expect($cases[0]->name)->toBe('Active');
    expect($cases[0]->value)->toBe('active');
    expect($cases[1]->name)->toBe('Inactive');
    expect($cases[2]->name)->toBe('Pending');
});

it('returns null for non-enum classes', function () {
    $mapper = new EnumDimensionMapper;

    $dimension = $mapper->map('column', 'stdClass');

    expect($dimension)->toBeNull();
});

it('handles non-existent enum classes gracefully', function () {
    $mapper = new EnumDimensionMapper;

    $result = $mapper->map('status', 'InvalidEnumClass');

    expect($result)->toBeNull();
});
