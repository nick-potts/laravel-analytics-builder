<?php

namespace NickPotts\Slice\Metrics\Aggregations;

use NickPotts\Slice\Metrics\AggregationCompiler;

class Sum extends Aggregation
{
    public static function make(string $reference): self
    {
        return new self($reference);
    }

    public static function registerCompilers(): void
    {
        AggregationCompiler::register(self::class, [
            'mysql' => fn($agg, $grammar) => 'SUM(' . $grammar->wrap($agg->getReference()) . ') AS ' . $grammar->wrap($agg->getAlias()),
            'pgsql' => fn($agg, $grammar) => 'SUM(' . $grammar->wrap($agg->getReference()) . ') AS ' . $grammar->wrap($agg->getAlias()),
            'sqlite' => fn($agg, $grammar) => 'SUM(' . $grammar->wrap($agg->getReference()) . ') AS ' . $grammar->wrap($agg->getAlias()),
        ]);
    }
}
