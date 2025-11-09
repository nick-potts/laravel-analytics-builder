<?php

namespace NickPotts\Slice\Schemas\Dimensions;

/**
 * String/categorical dimension for grouping analytics data by string values.
 *
 * Used for generic string columns like country, region, product SKU, etc.
 * Can optionally track a set of known values for validation/filtering.
 */
class StringDimension implements Dimension
{
    /** @var array<string> */
    private array $values = [];

    private function __construct(
        private readonly string $column,
        private readonly ?string $name = null,
    ) {}

    public static function make(string $column): self
    {
        return new self($column);
    }

    public function name(): string
    {
        return $this->name ?? $this->column;
    }

    public function column(): string
    {
        return $this->column;
    }

    /**
     * Create a new instance with a custom name.
     */
    public function withName(string $name): self
    {
        return new self($this->column, $name);
    }

    /**
     * Get known string values.
     *
     * @return array<string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Set known string values.
     *
     * @param  array<string>  $values
     */
    public function withValues(array $values): self
    {
        $dimension = new self($this->column, $this->name);
        $dimension->values = $values;

        return $dimension;
    }

    /**
     * Add a known string value.
     */
    public function addValue(string $value): self
    {
        $this->values[] = $value;

        return $this;
    }
}
