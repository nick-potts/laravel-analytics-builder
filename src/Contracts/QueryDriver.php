<?php

namespace NickPotts\Slice\Contracts;

use NickPotts\Slice\Engine\Grammar\QueryGrammar;

interface QueryDriver
{
    /**
     * Driver identifier (mysql, pgsql, clickhouse, etc).
     */
    public function name(): string;

    /**
     * Create a fresh query adapter targeting the given table.
     */
    public function createQuery(?string $table = null): QueryAdapter;

    /**
     * Get the grammar responsible for driver-specific SQL fragments.
     */
    public function grammar(): QueryGrammar;

    /**
     * Determine if this driver can perform joins directly in the query execution layer.
     */
    public function supportsDatabaseJoins(): bool;

    /**
     * Determine if this driver supports Common Table Expressions (CTEs / WITH clauses).
     */
    public function supportsCTEs(): bool;
}
