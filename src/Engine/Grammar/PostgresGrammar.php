<?php

namespace NickPotts\Slice\Engine\Grammar;

class PostgresGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        $fullColumn = "{$table}.{$column}";

        return match ($granularity) {
            'hour' => "date_trunc('hour', {$fullColumn})",
            'day' => "date_trunc('day', {$fullColumn})",
            'week' => "date_trunc('week', {$fullColumn})",
            'month' => "date_trunc('month', {$fullColumn})",
            'year' => "date_trunc('year', {$fullColumn})",
            default => throw new \InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };
    }
}
