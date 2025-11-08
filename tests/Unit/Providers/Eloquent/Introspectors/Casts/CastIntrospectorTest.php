<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Casts\CastIntrospector;
use Workbench\App\Models\Order;

it('discovers all casts from model', function () {
    $introspector = new CastIntrospector();
    $model = new Order();

    $casts = $introspector->discoverCasts($model);

    // Order should have created_at and updated_at
    expect($casts)->toHaveKey('created_at');
    expect($casts)->toHaveKey('updated_at');
});

it('discovers temporal columns backward compatibility', function () {
    $introspector = new CastIntrospector();
    $model = new Order();

    $columns = $introspector->discoverTemporalColumns($model);

    // Order should have created_at and updated_at as timestamps
    expect($columns)->toHaveKey('created_at');
    expect($columns['created_at'])->toBe('timestamp');
    expect($columns)->toHaveKey('updated_at');
    expect($columns['updated_at'])->toBe('timestamp');
});

it('marks casts with metadata', function () {
    $introspector = new CastIntrospector();
    $model = new Order();

    $casts = $introspector->discoverCasts($model);

    // Check that CastInfo objects have metadata
    expect($casts['created_at']->column)->toBe('created_at');
    expect($casts['created_at']->castType)->toBe('timestamp');
    expect($casts['created_at']->isEnum)->toBeFalse();
    expect($casts['created_at']->isCustom)->toBeFalse();
});
