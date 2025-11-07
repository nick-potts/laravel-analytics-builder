<?php

namespace NickPotts\Slice\Engine\Drivers;

use Illuminate\Database\Query\Builder;
use NickPotts\Slice\Contracts\QueryAdapter;

class LaravelQueryAdapter implements QueryAdapter
{
    public function __construct(
        protected Builder $builder
    ) {}

    public function selectRaw(string $expression): void
    {
        $this->builder->selectRaw($expression);
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): void
    {
        $this->builder->join($table, $first, $operator, $second, $type);
    }

    public function groupBy(string $column): void
    {
        $this->builder->groupBy($column);
    }

    public function groupByRaw(string $expression): void
    {
        $this->builder->groupByRaw($expression);
    }

    public function whereIn(string $column, array $values): void
    {
        $this->builder->whereIn($column, $values);
    }

    public function whereNotIn(string $column, array $values): void
    {
        $this->builder->whereNotIn($column, $values);
    }

    public function where(string $column, string $operator, mixed $value): void
    {
        $this->builder->where($column, $operator, $value);
    }

    public function execute(): array
    {
        return $this->builder->get()->toArray();
    }

    public function getDriverName(): string
    {
        return $this->builder->getConnection()->getDriverName();
    }

    public function getNative(): mixed
    {
        return $this->builder;
    }
}
