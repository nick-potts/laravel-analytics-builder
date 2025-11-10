<?php

namespace NickPotts\Slice\Tests\Support;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

class MockSliceSource implements SliceSource
{
    private string $providerName;

    private ?string $connectionName;

    private string $tableName;

    private ?string $sqlTable;

    private ?string $sql;

    private RelationGraph $relations;

    private DimensionCatalog $dimensions;

    private array $meta;

    public function __construct(
        string $tableName,
        ?string $connection = null,
        ?RelationGraph $relations = null,
        ?DimensionCatalog $dimensions = null,
        string $provider = 'eloquent',
        ?string $sqlTable = null,
        ?string $sql = null,
        array $meta = [],
    ) {
        $this->tableName = $tableName;
        $this->providerName = $provider;
        $this->connectionName = $connection;
        $this->sqlTable = $sqlTable ?? $tableName;
        $this->sql = $sql;
        $this->relations = $relations ?? new RelationGraph;
        $this->dimensions = $dimensions ?? new DimensionCatalog;
        $this->meta = $meta;
    }

    public function identifier(): string
    {
        $connectionPart = $this->connectionName ?? 'null';
        return "{$this->providerName}:{$connectionPart}:{$this->tableName}";
    }

    public function name(): string
    {
        return $this->tableName;
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function connection(): ?string
    {
        return $this->connectionName;
    }

    public function sqlTable(): ?string
    {
        return $this->sqlTable;
    }

    public function sql(): ?string
    {
        return $this->sql;
    }

    public function relations(): RelationGraph
    {
        return $this->relations;
    }

    public function dimensions(): DimensionCatalog
    {
        return $this->dimensions;
    }

    public function meta(): array
    {
        return $this->meta;
    }
}
