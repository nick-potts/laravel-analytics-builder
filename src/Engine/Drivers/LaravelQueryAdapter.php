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
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->builder->getConnection();

        return $connection->getDriverName();
    }

    public function getNative(): mixed
    {
        return $this->builder;
    }

    public function withExpression(string $name, \Closure|QueryAdapter $query): void
    {
        // Ensure we're using the CTE-enabled builder
        if (! method_exists($this->builder, 'withExpression')) {
            throw new \RuntimeException(
                'CTE support requires staudenmeir/laravel-cte package. '.
                'Run: composer require staudenmeir/laravel-cte'
            );
        }

        if ($query instanceof QueryAdapter) {
            // Extract the native Laravel builder
            $query = $query->getNative();
        }

        $this->builder->withExpression($name, $query);
    }

    public function from(string $table): void
    {
        $this->builder->from($table);
    }

    public function select(string|array $columns): void
    {
        $this->builder->select($columns);
    }

    public function supportsCTEs(): bool
    {
        return method_exists($this->builder, 'withExpression');
    }
}
