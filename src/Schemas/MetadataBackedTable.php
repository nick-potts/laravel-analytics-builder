<?php

namespace NickPotts\Slice\Schemas;

use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * TableContract implementation backed by ModelMetadata.
 *
 * Used by EloquentSchemaProvider and other providers to expose
 * introspected metadata as a TableContract.
 */
final class MetadataBackedTable implements TableContract
{
    public function __construct(private readonly ModelMetadata $metadata) {}

    public function name(): string
    {
        return $this->metadata->tableName;
    }

    public function connection(): string
    {
        $connection = $this->metadata->connection ?? config('database.default', 'default');
        return "eloquent:$connection";
    }

    public function primaryKey(): PrimaryKeyDescriptor
    {
        return $this->metadata->primaryKey;
    }

    public function relations(): RelationGraph
    {
        return $this->metadata->relationGraph;
    }

    public function dimensions(): DimensionCatalog
    {
        return $this->metadata->dimensionCatalog;
    }

    /**
     * Get the underlying metadata.
     *
     * Useful for accessing model-specific information.
     */
    public function metadata(): ModelMetadata
    {
        return $this->metadata;
    }

    /**
     * Get the model class this table is backed by.
     */
    public function modelClass(): string
    {
        return $this->metadata->modelClass;
    }

    /**
     * Check if this table's model uses soft deletes.
     */
    public function hasSoftDeletes(): bool
    {
        return $this->metadata->softDeletes;
    }

    /**
     * Check if this table's model uses timestamps.
     */
    public function hasTimestamps(): bool
    {
        return $this->metadata->timestamps;
    }
}
