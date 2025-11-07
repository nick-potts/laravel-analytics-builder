<?php

namespace NickPotts\Slice\Schemas;

class Dimension
{
    protected string $name;
    protected ?string $label = null;
    protected ?string $column = null;
    protected string $type = 'string';
    protected array $meta = [];
    protected array $filters = [];

    public static function make(string $name): static
    {
        $dimension = new static();
        $dimension->name = $name;

        return $dimension;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function meta(array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Filter to only include specific values.
     */
    public function only(array $values): static
    {
        $this->filters['only'] = $values;

        return $this;
    }

    /**
     * Filter to exclude specific values.
     */
    public function except(array $values): static
    {
        $this->filters['except'] = $values;

        return $this;
    }

    /**
     * Add a custom where condition.
     */
    public function where(string $operator, mixed $value): static
    {
        $this->filters['where'] = ['operator' => $operator, 'value' => $value];

        return $this;
    }

    /**
     * Get the filters applied to this dimension.
     */
    public function filters(): array
    {
        return $this->filters;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label ?? $this->name,
            'column' => $this->column ?? $this->name,
            'type' => $this->type,
            'meta' => $this->meta,
            'filters' => $this->filters,
        ];
    }
}
