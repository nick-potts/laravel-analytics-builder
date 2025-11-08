<?php

namespace NickPotts\Slice\Engine\Drivers;

use NickPotts\Slice\Contracts\QueryAdapter;

/**
 * Query adapter for ClickHouse database.
 *
 * Builds SQL queries manually since ClickHouse uses a different client than Laravel's query builder.
 * Requires: composer require tinkerbell/clickhouse-php (or similar ClickHouse client)
 */
class ClickHouseQueryAdapter implements QueryAdapter
{
    protected array $selects = [];

    protected array $joins = [];

    protected array $wheres = [];

    protected array $groupBys = [];

    protected array $orderBys = [];

    protected array $ctes = [];

    protected ?string $fromTable = null;

    public function __construct(
        protected mixed $client,
        ?string $table = null
    ) {
        $this->fromTable = $table;
    }

    public function selectRaw(string $expression): void
    {
        $this->selects[] = $expression;
    }

    public function select(string|array $columns): void
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $column) {
            $this->selects[] = $column;
        }
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): void
    {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
    }

    public function groupBy(string $column): void
    {
        $this->groupBys[] = $column;
    }

    public function groupByRaw(string $expression): void
    {
        $this->groupBys[] = $expression;
    }

    public function orderBy(string $column, string $direction = 'asc'): void
    {
        $this->orderBys[] = "{$column} {$direction}";
    }

    public function orderByRaw(string $expression): void
    {
        $this->orderBys[] = $expression;
    }

    public function whereIn(string $column, array $values): void
    {
        $valueList = implode(', ', array_map(fn ($v) => $this->quote($v), $values));
        $this->wheres[] = "{$column} IN ({$valueList})";
    }

    public function whereNotIn(string $column, array $values): void
    {
        $valueList = implode(', ', array_map(fn ($v) => $this->quote($v), $values));
        $this->wheres[] = "{$column} NOT IN ({$valueList})";
    }

    public function where(string $column, string $operator, mixed $value): void
    {
        $this->wheres[] = "{$column} {$operator} {$this->quote($value)}";
    }

    public function withExpression(string $name, \Closure|QueryAdapter $query): void
    {
        if ($query instanceof QueryAdapter) {
            // Build the subquery SQL
            $sql = $this->buildSQL($query);
        } elseif ($query instanceof \Closure) {
            $subquery = new self($this->client);
            $query($subquery);
            $sql = $this->buildSQL($subquery);
        } else {
            throw new \InvalidArgumentException('CTE query must be Closure or QueryAdapter');
        }

        $this->ctes[$name] = $sql;
    }

    public function from(string $table): void
    {
        $this->fromTable = $table;
    }

    public function execute(): array
    {
        $sql = $this->toSQL();

        // Check if client has a select method (ClickHouse-specific)
        if (method_exists($this->client, 'select')) {
            $result = $this->client->select($sql);

            return $result->rows();
        }

        // Fallback: If client is a callable (for testing)
        if (is_callable($this->client)) {
            return ($this->client)($sql);
        }

        throw new \RuntimeException('ClickHouse client must implement select() method or be callable');
    }

    public function getDriverName(): string
    {
        return 'clickhouse';
    }

    public function getNative(): mixed
    {
        return $this->client;
    }

    public function supportsCTEs(): bool
    {
        return true;
    }

    /**
     * Build the complete SQL query string.
     */
    public function toSQL(): string
    {
        $parts = [];

        // WITH clauses (CTEs)
        if (! empty($this->ctes)) {
            $cteStrings = [];
            foreach ($this->ctes as $name => $sql) {
                $cteStrings[] = "{$name} AS ({$sql})";
            }
            $parts[] = 'WITH '.implode(', ', $cteStrings);
        }

        // SELECT
        $selects = empty($this->selects) ? '*' : implode(', ', $this->selects);
        $parts[] = "SELECT {$selects}";

        // FROM
        if ($this->fromTable) {
            $parts[] = "FROM {$this->fromTable}";
        }

        // JOINs
        foreach ($this->joins as $join) {
            $parts[] = "{$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        // WHERE
        if (! empty($this->wheres)) {
            $parts[] = 'WHERE '.implode(' AND ', $this->wheres);
        }

        // GROUP BY
        if (! empty($this->groupBys)) {
            $parts[] = 'GROUP BY '.implode(', ', $this->groupBys);
        }

        // ORDER BY
        if (! empty($this->orderBys)) {
            $parts[] = 'ORDER BY '.implode(', ', $this->orderBys);
        }

        return implode(' ', $parts);
    }

    /**
     * Build SQL from another adapter.
     */
    protected function buildSQL(QueryAdapter $adapter): string
    {
        if ($adapter instanceof self) {
            return $adapter->toSQL();
        }

        // For other adapters, try to get the SQL
        $native = $adapter->getNative();
        if (method_exists($native, 'toSql')) {
            return $native->toSql();
        }

        throw new \RuntimeException('Cannot extract SQL from adapter');
    }

    /**
     * Quote a value for safe SQL inclusion.
     */
    protected function quote(mixed $value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'".addslashes((string) $value)."'";
    }
}
