<?php

namespace NickPotts\Slice\Support;

/**
 * Thrown when a table name is ambiguous (exists in multiple providers).
 *
 * Users must specify the provider explicitly using provider-prefixed syntax:
 * - 'eloquent:orders.total'
 * - 'clickhouse:events.count'
 */
class AmbiguousTableException extends \Exception
{
    public static function forTable(string $table, array $providers): self
    {
        $providerList = implode(', ', $providers);
        $suggestions = implode('\n', array_map(
            fn ($p) => "  - {$p}:{$table}.column",
            $providers
        ));

        return new self(
            "Ambiguous table '{$table}' found in multiple providers: {$providerList}.\n".
            "Specify the provider explicitly using one of these formats:\n{$suggestions}"
        );
    }
}
