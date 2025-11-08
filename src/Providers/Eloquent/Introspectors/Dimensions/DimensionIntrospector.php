<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use Illuminate\Database\Eloquent\Model;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Casts\CastIntrospector;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;

/**
 * Introspects Eloquent models to discover dimensions.
 *
 * Uses CastIntrospector to identify all casts, then uses DimensionMapperRegistry
 * to map cast types to appropriate Dimension instances.
 */
class DimensionIntrospector
{
    private CastIntrospector $castIntrospector;

    private DimensionMapperRegistry $mapperRegistry;

    public function __construct(
        ?CastIntrospector $castIntrospector = null,
        ?DimensionMapperRegistry $mapperRegistry = null,
    ) {
        $this->castIntrospector = $castIntrospector ?? new CastIntrospector();
        $this->mapperRegistry = $mapperRegistry ?? new DimensionMapperRegistry();
    }

    /**
     * Extract dimensions from all casts in a model.
     */
    public function introspect(Model $model): DimensionCatalog
    {
        $dimensions = [];

        foreach ($this->castIntrospector->discoverCasts($model) as $castInfo) {
            // Skip appended attributes (computed properties)
            if ($this->isAppendedAttribute($model, $castInfo->column)) {
                continue;
            }

            // Find mapper for this cast type
            $mapper = $this->mapperRegistry->getMapper($castInfo->castType);
            if (!$mapper) {
                continue;
            }

            // Create dimension
            $dimension = $mapper->map($castInfo->column, $castInfo->castType);
            if ($dimension) {
                // Use class name + column as key
                $key = get_class($dimension) . '::' . $castInfo->column;
                $dimensions[$key] = $dimension;
            }
        }

        return new DimensionCatalog($dimensions);
    }

    private function isAppendedAttribute(Model $model, string $column): bool
    {
        return in_array($column, $model->getAppends(), true);
    }
}
