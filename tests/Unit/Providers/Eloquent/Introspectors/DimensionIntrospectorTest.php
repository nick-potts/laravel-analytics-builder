<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\DimensionIntrospector;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;
use Workbench\App\Models\Order;

it('discovers time dimensions from datetime casts', function () {
    $introspector = new DimensionIntrospector;
    $model = new Order;

    $catalog = $introspector->introspect($model);

    // Order model has created_at and updated_at
    $timeDimensions = $catalog->ofType(TimeDimension::class);
    expect($timeDimensions)->not->toBeEmpty();
});

it('skips appended attributes', function () {
    $introspector = new DimensionIntrospector;
    $model = new Order;

    $catalog = $introspector->introspect($model);

    // Should only have real columns, not appended attributes
    expect($catalog->count())->toBeGreaterThanOrEqual(0);
});
