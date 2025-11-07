<?php

namespace NickPotts\Slice\Engine\Grammar;

class SingleStoreGrammar extends MySqlGrammar
{
    // SingleStore is MySQL-compatible for most operations
    // Extending MySqlGrammar provides baseline functionality
    // Override methods here for SingleStore-specific optimizations

    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        $fullColumn = "{$table}.{$column}";

        // SingleStore has optimized time functions
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
