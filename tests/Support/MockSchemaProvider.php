<?php

namespace NickPotts\Slice\Tests\Support;

use NickPotts\Slice\Contracts\SchemaProvider;
use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Support\Cache\SchemaCache;
use NickPotts\Slice\Support\MetricSource;

class MockSchemaProvider implements SchemaProvider
{
    private array $tables = [];

    private string $providerName = 'mock';

    public function boot(SchemaCache $cache): void {}

    public function tables(): iterable
    {
        return $this->tables;
    }

    public function provides(string $identifier): bool
    {
        return isset($this->tables[$identifier]);
    }

    public function resolveMetricSource(string $reference): MetricSource
    {
        [$tableName, $column] = explode('.', $reference, 2);

        if (! isset($this->tables[$tableName])) {
            throw new \InvalidArgumentException("Table '{$tableName}' not found");
        }

        return new MetricSource(
            $this->tables[$tableName],
            $column
        );
    }

    public function relations(string $table): RelationGraph
    {
        return new RelationGraph;
    }

    public function dimensions(string $table): DimensionCatalog
    {
        return new DimensionCatalog;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function registerTable(SliceSource $table): self
    {
        $this->tables[$table->name()] = $table;

        return $this;
    }

    public function setName(string $name): self
    {
        $this->providerName = $name;

        return $this;
    }
}
