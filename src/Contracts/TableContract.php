<?php

namespace NickPotts\Slice\Contracts;

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;
use NickPotts\Slice\Schemas\RelationGraph;

/**
 * Contract for a table from any schema provider.
 *
 * All providers must be able to resolve to a TableContract instance.
 * This could wrap:
 * - An Eloquent model (EloquentSchemaProvider)
 * - A manual Table class (ManualTableProvider)
 * - A ClickHouse system table (ClickHouseProvider)
 * - A static config (ConfigProvider)
 * - An API endpoint (OpenAPIProvider)
 */
interface TableContract
{
    /**
     * Get the table name.
     *
     * This is the identifier used in metric references.
     * Example: 'orders', 'customers', 'events'
     */
    public function name(): string;

    /**
     * Get the database connection name.
     *
     * Used to determine which database driver to use.
     * Returns null for non-database sources.
     *
     * @return string|null Connection name (e.g., 'mysql', 'pgsql') or null
     */
    public function connection(): ?string;

    /**
     * Get the primary key descriptor.
     *
     * Used to determine the "base table" when aggregating metrics.
     */
    public function primaryKey(): PrimaryKeyDescriptor;

    /**
     * Get all relations defined on this table.
     */
    public function relations(): RelationGraph;

    /**
     * Get all dimensions this table supports.
     */
    public function dimensions(): DimensionCatalog;
}
