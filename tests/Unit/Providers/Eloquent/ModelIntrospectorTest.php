<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\DimensionIntrospector;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Keys\PrimaryKeyIntrospector;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Relations\RelationIntrospector;
use NickPotts\Slice\Providers\Eloquent\ModelIntrospector;
use Workbench\App\Models\Order;

it('introspects complete model metadata', function () {
    $introspector = new ModelIntrospector(
        new PrimaryKeyIntrospector,
        new RelationIntrospector,
        new DimensionIntrospector,
    );

    $metadata = $introspector->introspect(Order::class);

    expect($metadata->modelClass)->toBe(Order::class);
    expect($metadata->tableName)->toBe('orders');
    expect($metadata->primaryKey->isSingle())->toBeTrue();
});

it('extracts relations during introspection', function () {
    $introspector = new ModelIntrospector(
        new PrimaryKeyIntrospector,
        new RelationIntrospector,
        new DimensionIntrospector,
    );

    $metadata = $introspector->introspect(Order::class);

    expect($metadata->relationGraph->has('items'))->toBeTrue();
    expect($metadata->relationGraph->has('customer'))->toBeTrue();
});

it('extracts dimensions during introspection', function () {
    $introspector = new ModelIntrospector(
        new PrimaryKeyIntrospector,
        new RelationIntrospector,
        new DimensionIntrospector,
    );

    $metadata = $introspector->introspect(Order::class);

    expect($metadata->dimensionCatalog->count())->toBeGreaterThan(0);
});

it('detects soft deletes', function () {
    $introspector = new ModelIntrospector(
        new PrimaryKeyIntrospector,
        new RelationIntrospector,
        new DimensionIntrospector,
    );

    // Order doesn't have soft deletes
    $metadata = $introspector->introspect(Order::class);
    expect($metadata->softDeletes)->toBeFalse();
});

it('detects timestamps property', function () {
    $introspector = new ModelIntrospector(
        new PrimaryKeyIntrospector,
        new RelationIntrospector,
        new DimensionIntrospector,
    );

    $metadata = $introspector->introspect(Order::class);
    expect($metadata->timestamps)->toBeTrue();
});
