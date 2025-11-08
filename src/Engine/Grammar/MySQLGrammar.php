<?php

namespace NickPotts\Slice\Engine\Grammar;

use NickPotts\Slice\Contracts\QueryGrammar;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * MySQL/MariaDB-specific SQL grammar.
 */
class MySQLGrammar implements QueryGrammar
{
    public function name(): string
    {
        return 'mysql';
    }

    public function formatTimeBucket(string $column, TimeDimension $dimension): string
    {
        $granularity = $dimension->granularity();

        return match ($granularity) {
            'year' => "DATE_FORMAT({$column}, '%Y-01-01')",
            'quarter' => "CONCAT(YEAR({$column}), '-', LPAD(QUARTER({$column}) * 3 - 2, 2, '0'), '-01')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'week' => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            'day' => "DATE({$column})",
            'hour' => "DATE_FORMAT({$column}, '%Y-%m-%d %H:00:00')",
            'minute' => "DATE_FORMAT({$column}, '%Y-%m-%d %H:%i:00')",
            default => "DATE({$column})",
        };
    }

    public function wrap(string $value): string
    {
        // MySQL uses backticks
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }
}
