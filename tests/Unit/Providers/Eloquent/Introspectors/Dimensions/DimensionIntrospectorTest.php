<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\DimensionIntrospector;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;
use Workbench\App\Models\Order;

it('discovers time dimensions from datetime casts', function () {
    $introspector = new DimensionIntrospector;
    $model = new Order;

    $catalog = $introspector->introspect($model);

    // Order model has created_at and updated_at
    $timeDimensions = $catalog->ofType(TimeDimension::class);
    expect($timeDimensions)->toHaveCount(2);
    expect($timeDimensions)->toHaveKey(TimeDimension::class.'::created_at');
    expect($timeDimensions)->toHaveKey(TimeDimension::class.'::updated_at');
});

it('skips appended attributes', function () {
    $introspector = new DimensionIntrospector;
    $model = new Order;

    // Get all dimensions from the model
    $catalog = $introspector->introspect($model);

    // Verify that the catalog is populated (it has timestamps)
    expect($catalog->count())->toBeGreaterThan(0);

    // Verify no appended attributes are in the catalog
    $appends = $model->getAppends();
    $catalogKeys = $catalog->keys();

    foreach ($appends as $appendedAttribute) {
        foreach ($catalogKeys as $catalogKey) {
            // Each catalog key is like "ClassName::column_name"
            // Extract the column name and verify it's not an appended attribute
            [$_, $columnName] = explode('::', $catalogKey);
            expect($columnName)->not->toBe($appendedAttribute);
        }
    }
});
