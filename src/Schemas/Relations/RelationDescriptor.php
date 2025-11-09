<?php

namespace NickPotts\Slice\Schemas\Relations;

enum RelationType: string
{
    case BelongsTo = 'belongs_to';
    case HasMany = 'has_many';
    case HasOne = 'has_one';
    case BelongsToMany = 'belongs_to_many';
    case MorphTo = 'morph_to';
    case MorphMany = 'morph_many';
}

/**
 * Describes a relation from one table to another.
 *
 * Used to build the relation graph for automatic join resolution.
 */
final class RelationDescriptor
{
    public function __construct(
        public readonly string $name,
        public readonly RelationType $type,
        public readonly string $targetModel,
        public readonly array $keys,
        public readonly ?string $pivot = null,
    ) {}

    /**
     * Get the target table name.
     *
     * For auto-discovered relations, this is the target model class.
     * The query engine will resolve the table name later.
     */
    public function target(): string
    {
        return $this->targetModel;
    }

    /**
     * Check if this is a "one" relation (BelongsTo, HasOne, MorphTo).
     */
    public function isOne(): bool
    {
        return in_array($this->type, [
            RelationType::BelongsTo,
            RelationType::HasOne,
            RelationType::MorphTo,
        ]);
    }

    /**
     * Check if this is a "many" relation (HasMany, MorphMany, BelongsToMany).
     */
    public function isMany(): bool
    {
        return in_array($this->type, [
            RelationType::HasMany,
            RelationType::MorphMany,
            RelationType::BelongsToMany,
        ]);
    }

    /**
     * Check if this is a pivot relation (BelongsToMany).
     */
    public function isPivot(): bool
    {
        return $this->type === RelationType::BelongsToMany;
    }
}
