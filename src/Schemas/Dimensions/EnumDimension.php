<?php

namespace NickPotts\Slice\Schemas\Dimensions;

/**
 * Enum dimension for grouping analytics data by PHP enum values.
 *
 * Used for categorical data like order status, product category, etc.
 * Auto-discovered from Eloquent model enum casts (BackedEnum or native enums).
 */
class EnumDimension implements Dimension
{
    /** @var \BackedEnum[] */
    private array $cases = [];

    private function __construct(
        private readonly string $column,
        private readonly ?string $name = null,
        private readonly ?string $enumClass = null,
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
     * Get the enum class this dimension uses.
     */
    public function enumClass(): ?string
    {
        return $this->enumClass;
    }

    /**
     * Set the enum class.
     */
    public function withEnumClass(string $enumClass): self
    {
        return new self($this->column, $this->name, $enumClass);
    }

    /**
     * Get all enum cases.
     *
     * @return \BackedEnum[]
     */
    public function cases(): array
    {
        return $this->cases;
    }

    /**
     * Set the enum cases.
     *
     * @param  array<\BackedEnum>  $cases  Map of case names to values
     */
    public function withCases(array $cases): self
    {
        $dimension = new self($this->column, $this->name, $this->enumClass);
        $dimension->cases = $cases;

        return $dimension;
    }
}
