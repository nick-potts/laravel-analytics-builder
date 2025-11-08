<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\PrimaryKeyIntrospector;
use Workbench\App\Models\Order;

it('extracts primary key from model', function () {
    $introspector = new PrimaryKeyIntrospector();
    $model = new Order();

    $pk = $introspector->introspect($model);

    expect($pk->isSingle())->toBeTrue();
    expect($pk->column())->toBe('id');
    expect($pk->autoIncrement)->toBeTrue();
});
