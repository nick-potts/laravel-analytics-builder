<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors;

use Illuminate\Database\Eloquent\Model;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * Introspects Eloquent model casts to discover dimensions.
 */
class DimensionIntrospector
{
    /**
     * Extract dimensions from model casts.
     */
    public function introspect(Model $model): DimensionCatalog
    {
        $dimensions = [];
        $casts = $model->getCasts();

        foreach ($casts as $column => $castType) {
            // Skip appended attributes (not real columns)
            if (in_array($column, $model->getAppends())) {
                continue;
            }

            $dimension = $this->castToDimension($column, $castType);

            if ($dimension) {
                $dimensions[TimeDimension::class . '::' . $column] = $dimension;
            }
        }

        return new DimensionCatalog($dimensions);
    }

    /**
     * Convert a cast type to a dimension, if applicable.
     */
    private function castToDimension(string $column, string $castType): ?TimeDimension
    {
        // Map datetime/date casts to TimeDimension
        if (in_array($castType, ['datetime', 'date', 'timestamp', 'immutable_datetime', 'immutable_date'])) {
            $precision = in_array($castType, ['date', 'immutable_date']) ? 'date' : 'timestamp';

            return TimeDimension::make($column)->precision($precision);
        }

        return null;
    }
}