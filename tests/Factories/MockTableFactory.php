<?php

namespace NickPotts\Slice\Tests\Factories;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

class MockTableFactory
{
    private string $name;
    private array $relations = [];
    private ?RelationGraph $relationGraph = null;
    private string $provider = 'mock';
    private ?string $connection = null;
    private ?string $identifier = null;
    private ?DimensionCatalog $dimensions = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function provider(string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function connection(?string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    public function identifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function relations(array $relations): self
    {
        $this->relations = $relations;
        return $this;
    }

    public function relationGraph(RelationGraph $relationGraph): self
    {
        $this->relationGraph = $relationGraph;
        return $this;
    }

    public function dimensions(DimensionCatalog $dimensions): self
    {
        $this->dimensions = $dimensions;
        return $this;
    }

    public function build(): SliceSource
    {
        if ($this->relationGraph) {
            $relationGraph = $this->relationGraph;
        } else {
            $relationGraph = new RelationGraph;
            foreach ($this->relations as $relationName => $descriptor) {
                $relationGraph = new RelationGraph(
                    array_merge($relationGraph->all(), [$relationName => $descriptor])
                );
            }
        }

        if (!$this->identifier) {
            $connectionPart = $this->connection ?? 'null';
            $identifier = $this->provider . ':' . $connectionPart . ':' . $this->name;
        } else {
            $identifier = $this->identifier;
        }
        $dimensions = $this->dimensions ?? new DimensionCatalog;

        return new class(
            $this->name,
            $relationGraph,
            $identifier,
            $this->provider,
            $this->connection,
            $dimensions
        ) implements SliceSource {
            public function __construct(
                private string $tableName,
                private RelationGraph $relations,
                private string $id,
                private string $providerName,
                private ?string $connName,
                private DimensionCatalog $dimensionCatalog,
            ) {}

            public function identifier(): string
            {
                return $this->id;
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
                return $this->connName;
            }

            public function relations(): RelationGraph
            {
                return $this->relations;
            }

            public function dimensions(): DimensionCatalog
            {
                return $this->dimensionCatalog;
            }

            public function sqlTable(): ?string
            {
                return $this->tableName;
            }

            public function sql(): ?string
            {
                return null;
            }

            public function meta(): array
            {
                return [];
            }
        };
    }
}
