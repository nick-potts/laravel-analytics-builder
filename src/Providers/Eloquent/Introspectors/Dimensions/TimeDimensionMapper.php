<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * Maps date/datetime casts to TimeDimension instances.
 *
 * Handles Laravel datetime casts:
 * - datetime, immutable_datetime → precision: 'timestamp'
 * - timestamp, immutable_timestamp → precision: 'timestamp'
 * - date, immutable_date → precision: 'date'
 * - Custom format casts: datetime:Y-m-d H:i:s → precision based on format
 */
class TimeDimensionMapper implements DimensionMapper
{
    public function handles(): array
    {
        return [
            'date',
            'immutable_date',
            'datetime',
            'immutable_datetime',
            'timestamp',
            'immutable_timestamp',
        ];
    }

    public function map(string $column, string $castType): ?Dimension
    {
        // Determine precision based on cast type
        $baseCast = strtolower(strtok($castType, ':') ?: $castType);
        $precision = str_contains($baseCast, 'date') && !str_contains($baseCast, 'datetime')
            ? 'date'
            : 'timestamp';

        return TimeDimension::make($column)->precision($precision);
    }
}
