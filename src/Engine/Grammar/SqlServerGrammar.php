<?php

namespace NickPotts\Slice\Engine\Grammar;

class SqlServerGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        $fullColumn = "{$table}.{$column}";

        return match ($granularity) {
            'hour' => "DATEADD(hour, DATEDIFF(hour, 0, {$fullColumn}), 0)",
            'day' => "CAST({$fullColumn} AS DATE)",
            'week' => "DATEADD(week, DATEDIFF(week, 0, {$fullColumn}), 0)",
            'month' => "DATEADD(month, DATEDIFF(month, 0, {$fullColumn}), 0)",
            'year' => "DATEADD(year, DATEDIFF(year, 0, {$fullColumn}), 0)",
            default => throw new \InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };
    }
}
