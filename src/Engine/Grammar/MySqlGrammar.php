<?php

namespace NickPotts\Slice\Engine\Grammar;

class MySqlGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        $fullColumn = "{$table}.{$column}";

        return match ($granularity) {
            'hour' => "DATE_FORMAT({$fullColumn}, '%Y-%m-%d %H:00:00')",
            'day' => "DATE({$fullColumn})",
            'week' => "DATE_FORMAT({$fullColumn}, '%Y-%u')",
            'month' => "DATE_FORMAT({$fullColumn}, '%Y-%m')",
            'year' => "YEAR({$fullColumn})",
            default => throw new \InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };
    }
}
