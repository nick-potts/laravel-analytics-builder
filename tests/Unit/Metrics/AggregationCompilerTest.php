<?php

use NickPotts\Slice\Metrics\AggregationCompiler;
use NickPotts\Slice\Metrics\Aggregations\Sum;

it('registers aggregation compilers with default', function () {
    AggregationCompiler::reset();

    $compilers = [
        'default' => fn ($agg, $grammar) => 'SUM(...)',
    ];

    AggregationCompiler::register(Sum::class, $compilers);

    $registered = AggregationCompiler::getCompilers();
    expect($registered)->toHaveKey(Sum::class);
    expect($registered[Sum::class])->toHaveKey('default');
});

it('throws on missing aggregation compiler', function () {
    AggregationCompiler::reset();

    $sum = Sum::make('orders.total');
    $grammar = DB::connection('sqlite')->getQueryGrammar();

    expect(fn () => AggregationCompiler::compile($sum, $grammar))
        ->toThrow(\RuntimeException::class);
});

it('uses default compiler when driver-specific override not found', function () {
    AggregationCompiler::reset();

    $compilers = [
        'default' => fn ($agg, $grammar) => 'SUM('.$grammar->wrap($agg->getReference()).')',
    ];
    AggregationCompiler::register(Sum::class, $compilers);

    $sum = Sum::make('orders.total');
    $grammar = DB::connection('sqlite')->getQueryGrammar();

    $sql = AggregationCompiler::compile($sum, $grammar);
    expect($sql)->toContain('SUM(');
});

it('throws when default compiler is missing', function () {
    AggregationCompiler::reset();

    // Register without default
    AggregationCompiler::register(Sum::class, [
        'mysql' => fn ($agg, $grammar) => 'SUM(...)',
    ]);

    $sum = Sum::make('orders.total');
    $grammar = DB::connection('sqlite')->getQueryGrammar();

    expect(fn () => AggregationCompiler::compile($sum, $grammar))
        ->toThrow(\RuntimeException::class, 'default');
});

it('allows third-party packages to register driver-specific overrides', function () {
    // This demonstrates how a MongoDB package would extend support
    // without modifying core aggregation classes
    AggregationCompiler::reset();

    // Core package registers default
    $coreCompilers = [
        'default' => fn ($agg, $grammar) => 'SUM('.$grammar->wrap($agg->getReference()).')',
    ];
    AggregationCompiler::register(Sum::class, $coreCompilers);

    // Third-party MongoDB package adds driver-specific override
    $mongoCompilers = [
        'default' => fn ($agg, $grammar) => 'SUM('.$grammar->wrap($agg->getReference()).')',
        'mongo' => fn ($agg, $grammar) => 'sum('.$grammar->wrap($agg->getReference()).')',
    ];
    AggregationCompiler::register(Sum::class, $mongoCompilers);

    $registered = AggregationCompiler::getCompilers();
    expect($registered[Sum::class])->toHaveKeys(['default', 'mongo']);
});
