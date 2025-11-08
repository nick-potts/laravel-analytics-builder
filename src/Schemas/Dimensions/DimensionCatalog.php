<?php

namespace NickPotts\Slice\Schemas\Dimensions;

/**
 * Catalog of all dimensions available on a table.
 *
 * Maps dimension classes/names to their column implementations.
 * Different providers discover dimensions differently:
 * - EloquentSchemaProvider: From model casts (datetime → TimeDimension)
 * - ManualTableProvider: From Table::dimensions() method
 * - ClickHouseProvider: From column types in system tables
 */
final class DimensionCatalog
{
    /** @var array<string, Dimension> */
    private array $dimensions;

    /**
     * @param  array<string, Dimension>  $dimensions  Keyed by dimension key (e.g., 'TimeDimension', 'CountryDimension')
     */
    public function __construct(array $dimensions = [])
    {
        $this->dimensions = $dimensions;
    }

    /**
     * Get a specific dimension by key.
     *
     * @param  string  $key  Dimension key (class name or custom key)
     */
    public function get(string $key): ?Dimension
    {
        return $this->dimensions[$key] ?? null;
    }

    /**
     * Check if a dimension exists.
     */
    public function has(string $key): bool
    {
        return isset($this->dimensions[$key]);
    }

    /**
     * Get all dimensions.
     *
     * @return array<string, Dimension>
     */
    public function all(): array
    {
        return $this->dimensions;
    }

    /**
     * Get all dimension keys.
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->dimensions);
    }

    /**
     * Get dimensions of a specific type.
     *
     * @param  string  $dimensionClass  Class name to filter by
     * @return array<string, Dimension>
     */
    public function ofType(string $dimensionClass): array
    {
        return array_filter(
            $this->dimensions,
            fn ($dim) => $dim instanceof $dimensionClass
        );
    }

    /**
     * Count dimensions.
     */
    public function count(): int
    {
        return count($this->dimensions);
    }

    /**
     * Check if catalog is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->dimensions);
    }

    /**
     * Iterate over dimensions.
     */
    public function forEach(\Closure $callback): void
    {
        foreach ($this->dimensions as $key => $dimension) {
            $callback($key, $dimension);
        }
    }

    /**
     * Add a dimension to the catalog.
     */
    public function add(string $key, Dimension $dimension): self
    {
        $this->dimensions[$key] = $dimension;

        return $this;
    }

    /**
     * Create a new catalog with additional dimensions.
     */
    public function merge(self $other): self
    {
        return new self(array_merge($this->dimensions, $other->all()));
    }
}
