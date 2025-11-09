<?php

namespace NickPotts\Slice\Support;

use NickPotts\Slice\Contracts\TableContract;

/**
 * Value object representing a resolved metric source.
 *
 * Created when a provider resolves a metric reference like 'orders.total'.
 * Contains the resolved table, column name, and database connection.
 */
final class MetricSource
{
    public function __construct(
        public readonly TableContract $table,
        public readonly string $column,
        public readonly ?string $connection = null,
    ) {}

    /**
     * Get the fully qualified metric key (table.column).
     */
    public function key(): string
    {
        return $this->table->name().'.'.$this->column;
    }

    /**
     * Get the table name.
     */
    public function tableName(): string
    {
        return $this->table->name();
    }

    /**
     * Get the column name.
     */
    public function columnName(): string
    {
        return $this->column;
    }

    /**
     * Get the database connection (or table's default).
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? $this->table->connection();
    }
}
