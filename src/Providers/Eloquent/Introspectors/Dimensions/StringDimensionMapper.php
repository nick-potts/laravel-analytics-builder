<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Schemas\Dimensions\StringDimension;

/**
 * Maps string casts to StringDimension instances.
 *
 * Handles Laravel string casts:
 * - string
 *
 * This is a catch-all for generic string columns. More specific mappers
 * (like BooleanDimensionMapper, EnumDimensionMapper) are checked first.
 */
class StringDimensionMapper implements DimensionMapper
{
    public function handles(): array
    {
        return ['string'];
    }

    public function map(string $column, string $castType): ?Dimension
    {
        return StringDimension::make($column);
    }
}
