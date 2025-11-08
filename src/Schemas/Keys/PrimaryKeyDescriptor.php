<?php

namespace NickPotts\Slice\Schemas\Keys;

/**
 * Describes the primary key structure of a table.
 *
 * Used to determine the "base table" when aggregating metrics.
 * Different tables have different primary key strategies:
 * - Single incrementing: ['id'] with autoIncrement=true
 * - Composite: ['org_id', 'id']
 * - Non-incrementing: ['uuid']
 */
final class PrimaryKeyDescriptor
{
    public function __construct(
        public readonly array $columns,
        public readonly bool $autoIncrement = true,
    ) {}

    /**
     * Check if primary key is a single column.
     */
    public function isSingle(): bool
    {
        return count($this->columns) === 1;
    }

    /**
     * Check if primary key is composite.
     */
    public function isComposite(): bool
    {
        return count($this->columns) > 1;
    }

    /**
     * Get the primary key column (if single).
     */
    public function column(): ?string
    {
        return $this->isSingle() ? $this->columns[0] : null;
    }

    /**
     * Get all primary key columns.
     */
    public function getColumns(): array
    {
        return $this->columns;
    }
}
