<?php

namespace NickPotts\Slice\Engine\Joins;

use NickPotts\Slice\Schemas\Relations\RelationDescriptor;

/**
 * A single join specification: describes how to connect two tables.
 *
 * This is part of the database-agnostic join plan. Different executors
 * can interpret this and generate their native join syntax.
 */
final class JoinSpecification
{
    public function __construct(
        public readonly string $fromTable,
        public readonly string $toTable,
        public readonly RelationDescriptor $relation,
        public readonly string $type = 'left', // 'left', 'inner', 'right', 'cross'
    ) {}

    /**
     * Get the foreign key column name from this relation.
     */
    public function foreignKey(): ?string
    {
        return $this->relation->keys['foreign'] ?? null;
    }

    /**
     * Get the owner/primary key column name.
     */
    public function ownerKey(): ?string
    {
        return $this->relation->keys['owner'] ?? null;
    }

    /**
     * Get the local key for HasMany/HasOne relations.
     */
    public function localKey(): ?string
    {
        return $this->relation->keys['local'] ?? null;
    }

    /**
     * Get the related key for BelongsToMany relations.
     */
    public function relatedKey(): ?string
    {
        return $this->relation->keys['related'] ?? null;
    }

    /**
     * Get the pivot table name (for BelongsToMany).
     */
    public function pivotTable(): ?string
    {
        return $this->relation->pivot;
    }
}
