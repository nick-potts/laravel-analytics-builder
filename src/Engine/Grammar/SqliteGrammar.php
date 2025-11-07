<?php

namespace NickPotts\Slice\Engine\Grammar;

class SqliteGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        $fullColumn = "{$table}.{$column}";

        return match ($granularity) {
            'hour' => "strftime('%Y-%m-%d %H:00:00', {$fullColumn})",
            'day' => "date({$fullColumn})",
            'week' => "date({$fullColumn}, 'weekday 0', '-6 days')",
            'month' => "strftime('%Y-%m-01', {$fullColumn})",
            'year' => "strftime('%Y-01-01', {$fullColumn})",
            default => throw new \InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };
    }
}
