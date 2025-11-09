<?php

namespace NickPotts\Slice\Schemas\Dimensions;

/**
 * Boolean dimension for grouping analytics data by true/false values.
 *
 * Used for categorical binary data like is_active, is_premium, etc.
 * Auto-discovered from Eloquent model boolean casts.
 */
class BooleanDimension implements Dimension
{
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
}
