<?php

namespace NickPotts\Slice\Schemas;

use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * Holds introspected metadata about an Eloquent model.
 *
 * Created by EloquentSchemaProvider when scanning models.
 * Can be serialized to/from cache for faster startup times.
 */
final class ModelMetadata
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $tableName,
        public readonly ?string $connection,
        public readonly PrimaryKeyDescriptor $primaryKey,
        public readonly RelationGraph $relationGraph,
        public readonly DimensionCatalog $dimensionCatalog,
        public readonly bool $softDeletes = false,
        public readonly bool $timestamps = true,
    ) {}

    /**
     * Serialize to cache-friendly format.
     */
    public function toArray(): array
    {
        return [
            'modelClass' => $this->modelClass,
            'tableName' => $this->tableName,
            'connection' => $this->connection,
            'primaryKey' => [
                'columns' => $this->primaryKey->columns,
                'autoIncrement' => $this->primaryKey->autoIncrement,
            ],
            'relationGraph' => $this->serializeRelationGraph(),
            'dimensionCatalog' => $this->serializeDimensionCatalog(),
            'softDeletes' => $this->softDeletes,
            'timestamps' => $this->timestamps,
        ];
    }

    /**
     * Deserialize from cache.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            modelClass: $data['modelClass'],
            tableName: $data['tableName'],
            connection: $data['connection'],
            primaryKey: new PrimaryKeyDescriptor(
                columns: $data['primaryKey']['columns'],
                autoIncrement: $data['primaryKey']['autoIncrement'],
            ),
            relationGraph: self::deserializeRelationGraph($data['relationGraph']),
            dimensionCatalog: self::deserializeDimensionCatalog($data['dimensionCatalog']),
            softDeletes: $data['softDeletes'] ?? false,
            timestamps: $data['timestamps'] ?? true,
        );
    }

    /**
     * Serialize relation graph for caching.
     */
    private function serializeRelationGraph(): array
    {
        $relations = [];

        foreach ($this->relationGraph->all() as $name => $descriptor) {
            $relations[$name] = [
                'name' => $descriptor->name,
                'type' => $descriptor->type->value,
                'targetModel' => $descriptor->targetModel,
                'keys' => $descriptor->keys,
                'pivot' => $descriptor->pivot,
            ];
        }

        return $relations;
    }

    /**
     * Deserialize relation graph from cache.
     */
    private static function deserializeRelationGraph(array $data): RelationGraph
    {
        $relations = [];

        foreach ($data as $name => $descriptor) {
            // Import RelationType enum
            $type = \NickPotts\Slice\Schemas\Relations\RelationType::from($descriptor['type']);

            $relations[$name] = new \NickPotts\Slice\Schemas\Relations\RelationDescriptor(
                name: $descriptor['name'],
                type: $type,
                targetModel: $descriptor['targetModel'],
                keys: $descriptor['keys'],
                pivot: $descriptor['pivot'],
            );
        }

        return new RelationGraph($relations);
    }

    /**
     * Serialize dimension catalog for caching.
     *
     * Note: This is a simplified serialization. Full dimension caching
     * would require serializing dimension instances, which may not be
     * cacheable depending on the dimension implementation.
     */
    private function serializeDimensionCatalog(): array
    {
        // For now, return empty - dimensions will be re-discovered
        // This can be enhanced later if needed
        return [];
    }

    /**
     * Deserialize dimension catalog from cache.
     */
    private static function deserializeDimensionCatalog(array $data): DimensionCatalog
    {
        // Return empty catalog - dimensions will be rediscovered
        return new DimensionCatalog;
    }
}
