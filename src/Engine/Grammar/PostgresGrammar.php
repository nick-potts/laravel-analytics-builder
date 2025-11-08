<?php

namespace NickPotts\Slice\Engine\Grammar;

use NickPotts\Slice\Contracts\QueryGrammar;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * PostgreSQL-specific SQL grammar.
 */
class PostgresGrammar implements QueryGrammar
{
    public function name(): string
    {
        return 'postgres';
    }

    public function formatTimeBucket(string $column, TimeDimension $dimension): string
    {
        $granularity = $dimension->granularity();

        return match ($granularity) {
            'year' => "DATE_TRUNC('year', {$column})",
            'quarter' => "DATE_TRUNC('quarter', {$column})",
            'month' => "DATE_TRUNC('month', {$column})",
            'week' => "DATE_TRUNC('week', {$column})",
            'day' => "DATE_TRUNC('day', {$column})",
            'hour' => "DATE_TRUNC('hour', {$column})",
            'minute' => "DATE_TRUNC('minute', {$column})",
            default => "DATE_TRUNC('day', {$column})",
        };
    }

    public function wrap(string $value): string
    {
        // PostgreSQL uses double quotes
        if ($value === '*') {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
