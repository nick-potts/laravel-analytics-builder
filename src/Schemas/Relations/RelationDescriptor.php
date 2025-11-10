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
 * Uses table identifiers (e.g., 'eloquent:customers') instead of model class names
 * to enable pure schema-based relation resolution without model introspection.
 *
 * Used to build the relation graph for automatic join resolution.
 */
final class RelationDescriptor
{
    public function __construct(
        public readonly string $name,
        public readonly RelationType $type,
        public readonly string $targetTableIdentifier,
        public readonly array $keys,
        public readonly ?string $pivot = null,
    ) {}

    /**
     * Get the target table identifier.
     *
     * Example: 'eloquent:customers', 'manual:orders'
     *
     * Table identifier is schema-provider-namespaced and can be resolved
     * directly via CompiledSchema::resolveTable().
     */
    public function target(): string
    {
        return $this->targetTableIdentifier;
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
