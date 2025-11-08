<?php

namespace NickPotts\Slice\Engine\Drivers;

use Illuminate\Database\Query\Builder;
use NickPotts\Slice\Contracts\QueryAdapter;

/**
 * Adapter for Laravel Query Builder.
 *
 * Wraps Illuminate\Database\Query\Builder with our QueryAdapter interface.
 */
class LaravelQueryAdapter implements QueryAdapter
{
    public function __construct(
        protected Builder $query
    ) {}

    public function select(string|array $columns): static
    {
        $this->query->select($columns);

        return $this;
    }

    public function selectRaw(string $expression): static
    {
        $this->query->selectRaw($expression);

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->query->join($table, $first, $operator, $second);

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->query->leftJoin($table, $first, $operator, $second);

        return $this;
    }

    public function where(string $column, mixed $operator, mixed $value = null): static
    {
        $this->query->where($column, $operator, $value);

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->query->whereRaw($sql, $bindings);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->query->whereNull($column);

        return $this;
    }

    public function groupBy(string|array $columns): static
    {
        $this->query->groupBy($columns);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    public function orderByRaw(string $sql): static
    {
        $this->query->orderByRaw($sql);

        return $this;
    }

    public function withExpression(string $name, QueryAdapter|string $query): static
    {
        if ($query instanceof QueryAdapter) {
            $query = $query->getQuery();
        }

        $this->query->withExpression($name, $query);

        return $this;
    }

    public function get(): array
    {
        return $this->query->get()->toArray();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function toSql(): string
    {
        return $this->query->toSql();
    }
}
