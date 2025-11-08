<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use NickPotts\Slice\Schemas\Dimensions\BooleanDimension;
use NickPotts\Slice\Schemas\Dimensions\Dimension;

/**
 * Maps boolean casts to BooleanDimension instances.
 *
 * Handles Laravel boolean casts:
 * - bool, boolean
 */
class BooleanDimensionMapper implements DimensionMapper
{
    public function handles(): array
    {
        return ['bool', 'boolean'];
    }

    public function map(string $column, string $castType): ?Dimension
    {
        return BooleanDimension::make($column);
    }
}
