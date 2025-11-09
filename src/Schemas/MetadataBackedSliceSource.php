<?php

namespace NickPotts\Slice\Schemas;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * SliceSource implementation backed by ModelMetadata.
 *
 * Used by the Eloquent provider to expose model metadata to the engine.
 */
final class MetadataBackedSliceSource implements SliceSource
{
    public function __construct(private readonly ModelMetadata $metadata) {}

    public function identifier(): string
    {
        return $this->provider().':'.$this->name();
    }

    public function name(): string
    {
        return $this->metadata->tableName;
    }

    public function provider(): string
    {
        return 'eloquent';
    }

    public function connection(): string
    {
        $connection = $this->metadata->connection ?? config('database.default', 'default');

        return "eloquent:$connection";
    }

    public function sqlTable(): ?string
    {
        return $this->metadata->tableName;
    }

    public function sql(): ?string
    {
        return null;
    }

    public function relations(): RelationGraph
    {
        return $this->metadata->relationGraph;
    }

    public function dimensions(): DimensionCatalog
    {
        return $this->metadata->dimensionCatalog;
    }

    public function meta(): array
    {
        return [
            'model' => $this->metadata->modelClass,
            'soft_deletes' => $this->metadata->softDeletes,
            'timestamps' => $this->metadata->timestamps,
        ];
    }

    public function metadata(): ModelMetadata
    {
        return $this->metadata;
    }
}
