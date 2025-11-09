<?php

namespace NickPotts\Slice\Support;

use NickPotts\Slice\Contracts\SliceSource;

/**
 * Value object representing a resolved metric source.
 *
 * Created when a provider resolves a metric reference like 'orders.total'.
 * Contains the resolved slice definition, column name, and connection override.
 */
final class MetricSource
{
    public readonly SliceDefinition $slice;

    /**
     * @deprecated Use $slice instead.
     */
    public readonly SliceDefinition $table;

    public function __construct(
        SliceSource $slice,
        public readonly string $column,
        public readonly ?string $connection = null,
    ) {
        $definition = $slice instanceof SliceDefinition
            ? $slice
            : SliceDefinition::fromSource($slice);

        $this->slice = $definition;
        $this->table = $definition;
    }

    /**
     * Get the fully qualified metric key (slice.identifier + column).
     */
    public function key(): string
    {
        return $this->slice->identifier().'.'.$this->column;
    }

    /**
     * Get the slice/table name.
     */
    public function tableName(): string
    {
        return $this->slice->name();
    }

    /**
     * Convenience accessor for the slice identifier.
     */
    public function sliceIdentifier(): string
    {
        return $this->slice->identifier();
    }

    /**
     * Get the column name.
     */
    public function columnName(): string
    {
        return $this->column;
    }

    /**
     * Get the database connection (override or slice default).
     */
    public function getConnection(): string
    {
        return $this->connection ?? $this->slice->connection();
    }
}
