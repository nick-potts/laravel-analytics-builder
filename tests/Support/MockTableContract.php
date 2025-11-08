<?php

namespace NickPotts\Slice\Tests\Support;

use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

class MockTableContract implements TableContract
{
    private string $tableName;

    private ?string $connectionName = null;

    private PrimaryKeyDescriptor $primaryKey;

    private RelationGraph $relations;

    private DimensionCatalog $dimensions;

    public function __construct(
        string $tableName,
        ?string $connection = null,
        ?PrimaryKeyDescriptor $primaryKey = null,
        ?RelationGraph $relations = null,
        ?DimensionCatalog $dimensions = null,
    ) {
        $this->tableName = $tableName;
        $this->connectionName = $connection;
        $this->primaryKey = $primaryKey ?? new PrimaryKeyDescriptor(['id']);
        $this->relations = $relations ?? new RelationGraph;
        $this->dimensions = $dimensions ?? new DimensionCatalog;
    }

    public function name(): string
    {
        return $this->tableName;
    }

    public function connection(): ?string
    {
        return $this->connectionName;
    }

    public function primaryKey(): PrimaryKeyDescriptor
    {
        return $this->primaryKey;
    }

    public function relations(): RelationGraph
    {
        return $this->relations;
    }

    public function dimensions(): DimensionCatalog
    {
        return $this->dimensions;
    }
}
