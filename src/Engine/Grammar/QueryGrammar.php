<?php

namespace NickPotts\Slice\Engine\Grammar;

abstract class QueryGrammar
{
    /**
     * Build the SQL expression for a time dimension bucket.
     */
    abstract public function formatTimeBucket(string $table, string $column, string $granularity): string;
}
