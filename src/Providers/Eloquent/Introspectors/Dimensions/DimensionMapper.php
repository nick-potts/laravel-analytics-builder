<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use NickPotts\Slice\Schemas\Dimensions\Dimension;

/**
 * Interface for mapping Eloquent casts to Dimension instances.
 *
 * Different cast types (datetime, enum, boolean, etc.) map to different
 * dimension implementations. Mappers are used by DimensionMapperRegistry
 * to automatically discover and create dimensions from model casts.
 *
 * Each mapper must declare which cast types it handles, and conflicts
 * (multiple mappers supporting the same cast type) will throw an error.
 */
interface DimensionMapper
{
    /**
     * Get the cast types this mapper handles.
     *
     * @return array<string>  Array of cast type names or patterns
     */
    public function handles(): array;

    /**
     * Create a Dimension instance from the cast type.
     *
     * @param  string  $column  The database column name
     * @param  string  $castType  The cast type
     * @return Dimension|null  Null if mapper decides not to create a dimension
     */
    public function map(string $column, string $castType): ?Dimension;
}
