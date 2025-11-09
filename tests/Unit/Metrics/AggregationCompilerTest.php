<?php

use NickPotts\Slice\Metrics\AggregationCompiler;
use NickPotts\Slice\Metrics\Aggregations\Sum;

it('registers aggregation compilers', function () {
    AggregationCompiler::reset();

    $compilers = [
        'mysql' => fn ($agg, $grammar) => 'SUM(...)',
        'pgsql' => fn ($agg, $grammar) => 'SUM(...)',
    ];

    AggregationCompiler::register(Sum::class, $compilers);

    $registered = AggregationCompiler::getCompilers();
    expect($registered)->toHaveKey(Sum::class);
    expect($registered[Sum::class])->toHaveKeys(['mysql', 'pgsql']);
});

it('throws on missing aggregation compiler', function () {
    AggregationCompiler::reset();

    $sum = Sum::make('orders.total');
    $grammar = DB::connection('sqlite')->getQueryGrammar();

    expect(fn () => AggregationCompiler::compile($sum, $grammar))
        ->toThrow(\RuntimeException::class);
})->skip('Needs compiler registered');

it('throws on missing driver compiler', function () {
    AggregationCompiler::reset();

    $compilers = [
        'mysql' => fn ($agg, $grammar) => 'SUM(...)',
    ];
    AggregationCompiler::register(Sum::class, $compilers);

    $sum = Sum::make('orders.total');
    $grammar = DB::connection('sqlite')->getQueryGrammar();

    expect(fn () => AggregationCompiler::compile($sum, $grammar))
        ->toThrow(\RuntimeException::class);
});
