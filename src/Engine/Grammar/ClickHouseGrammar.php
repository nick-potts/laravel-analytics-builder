<?php

namespace NickPotts\Slice\Engine\Grammar;

class ClickHouseGrammar extends QueryGrammar
{
    /**
     * Format time bucketing SQL for ClickHouse.
     * Uses ClickHouse-specific date/time functions.
     */
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        return match ($granularity) {
            'hour' => "toStartOfHour({$table}.{$column})",
            'day' => "toStartOfDay({$table}.{$column})",
            'week' => "toMonday({$table}.{$column})",
            'month' => "toStartOfMonth({$table}.{$column})",
            'year' => "toStartOfYear({$table}.{$column})",
            default => throw new \InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };
    }
}
