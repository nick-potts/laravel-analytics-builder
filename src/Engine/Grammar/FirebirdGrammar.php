<?php

namespace NickPotts\Slice\Engine\Grammar;

class FirebirdGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        $fullColumn = "{$table}.{$column}";

        return match ($granularity) {
            'hour' => "CAST(CAST({$fullColumn} AS DATE) || ' ' || EXTRACT(HOUR FROM {$fullColumn}) || ':00:00' AS TIMESTAMP)",
            'day' => "CAST({$fullColumn} AS DATE)",
            'week' => "DATEADD(day, -EXTRACT(WEEKDAY FROM {$fullColumn}), CAST({$fullColumn} AS DATE))",
            'month' => "CAST(EXTRACT(YEAR FROM {$fullColumn}) || '-' || EXTRACT(MONTH FROM {$fullColumn}) || '-01' AS DATE)",
            'year' => "CAST(EXTRACT(YEAR FROM {$fullColumn}) || '-01-01' AS DATE)",
            default => throw new \InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };
    }
}
