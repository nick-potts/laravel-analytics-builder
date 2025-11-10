<?php

namespace NickPotts\Slice\Support;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;

/**
 * Immutable definition of a Slice used at runtime.
 *
 * Created from provider SliceSources during manifest/build steps so the
 * engine can work with normalized metadata.
 */
final class SliceDefinition implements SliceSource
{
    public function __construct(
        private readonly string $name,
        private readonly string $provider,
        private readonly ?string $connection,
        private readonly ?string $sqlTable,
        private readonly ?string $sql,
        private readonly RelationGraph $relations,
        private readonly DimensionCatalog $dimensions,
        private readonly array $meta = [],
    ) {}

    public static function fromSource(SliceSource $source): self
    {
        return new self(
            name: $source->name(),
            provider: $source->provider(),
            connection: $source->connection(),
            sqlTable: $source->sqlTable(),
            sql: $source->sql(),
            relations: $source->relations(),
            dimensions: $source->dimensions(),
            meta: $source->meta(),
        );
    }

    public function identifier(): string
    {
        $connectionPart = $this->connection ?? 'null';

        return $this->provider.':'.$connectionPart.':'.$this->name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function connection(): ?string
    {
        return $this->connection;
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
