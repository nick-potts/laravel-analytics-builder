<?php

namespace NickPotts\Slice\Engine\Grammar;

use NickPotts\Slice\Contracts\QueryGrammar;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * SQLite-specific SQL grammar.
 */
class SqliteGrammar implements QueryGrammar
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function formatTimeBucket(string $column, TimeDimension $dimension): string
    {
        $granularity = $dimension->granularity();

        return match ($granularity) {
            'year' => "strftime('%Y-01-01', {$column})",
            'month' => "strftime('%Y-%m-01', {$column})",
            'week' => "date({$column}, 'weekday 0', '-7 days')",
            'day' => "date({$column})",
            'hour' => "strftime('%Y-%m-%d %H:00:00', {$column})",
            'minute' => "strftime('%Y-%m-%d %H:%M:00', {$column})",
            default => "date({$column})",
        };
    }

    public function wrap(string $value): string
    {
        // SQLite uses double quotes
        if ($value === '*') {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
