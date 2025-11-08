<?php

namespace NickPotts\Slice\Providers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions\DimensionIntrospector;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Keys\PrimaryKeyIntrospector;
use NickPotts\Slice\Providers\Eloquent\Introspectors\Relations\RelationIntrospector;
use NickPotts\Slice\Schemas\ModelMetadata;

/**
 * Coordinates introspection of an Eloquent model.
 *
 * Delegates to specialized introspectors for relations, dimensions, and keys.
 */
class ModelIntrospector
{
    public function __construct(
        private PrimaryKeyIntrospector $primaryKeyIntrospector,
        private RelationIntrospector $relationIntrospector,
        private DimensionIntrospector $dimensionIntrospector,
    ) {}

    /**
     * Introspect a model and return its metadata.
     */
    public function introspect(string $modelClass): ModelMetadata
    {
        $reflection = new \ReflectionClass($modelClass);
        /** @var Model $model */
        $model = $reflection->newInstanceWithoutConstructor();
        $model->syncOriginal();

        return new ModelMetadata(
            modelClass: $modelClass,
            tableName: $model->getTable(),
            connection: $model->getConnectionName(),
            primaryKey: $this->primaryKeyIntrospector->introspect($model),
            relationGraph: $this->relationIntrospector->introspect($modelClass, $reflection),
            dimensionCatalog: $this->dimensionIntrospector->introspect($model),
            softDeletes: $this->hasSoftDeletes($modelClass),
            timestamps: $model->timestamps,
        );
    }

    /**
     * Check if a model uses soft deletes.
     */
    private function hasSoftDeletes(string $modelClass): bool
    {
        return in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($modelClass)
        );
    }
}
