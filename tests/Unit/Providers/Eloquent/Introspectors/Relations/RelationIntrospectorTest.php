<?php

use NickPotts\Slice\Providers\Eloquent\Introspectors\Relations\RelationIntrospector;
use NickPotts\Slice\Schemas\Relations\RelationType;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;

it('detects belongs to relations', function () {
    $introspector = new RelationIntrospector;
    $reflection = new ReflectionClass(OrderItem::class);

    $graph = $introspector->introspect(OrderItem::class, $reflection);

    expect($graph->has('order'))->toBeTrue();
    $order = $graph->get('order');
    expect($order->type)->toBe(RelationType::BelongsTo);
    expect($order->targetModel)->toBe(Order::class);
});

it('detects has many relations', function () {
    $introspector = new RelationIntrospector;
    $reflection = new ReflectionClass(Order::class);

    $graph = $introspector->introspect(Order::class, $reflection);

    expect($graph->has('items'))->toBeTrue();
    $items = $graph->get('items');
    expect($items->type)->toBe(RelationType::HasMany);
    expect($items->targetModel)->toBe(OrderItem::class);
});

it('returns empty graph for model without relations', function () {
    $introspector = new RelationIntrospector;
    $reflection = new ReflectionClass(\Workbench\App\Models\Product::class);

    $graph = $introspector->introspect(\Workbench\App\Models\Product::class, $reflection);

    // Product model has no defined relations
    expect($graph->count())->toBe(0);
    expect($graph->all())->toBeEmpty();
});
