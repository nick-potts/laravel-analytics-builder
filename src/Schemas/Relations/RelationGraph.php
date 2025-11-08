<?php

namespace NickPotts\Slice\Schemas\Relations;

/**
 * Holds all relations for a table.
 *
 * Used by providers to describe how a table relates to others,
 * enabling automatic join resolution.
 */
final class RelationGraph
{
    /** @var array<string, RelationDescriptor> */
    private array $relations;

    /**
     * @param  array<string, RelationDescriptor>  $relations  Keyed by relation name
     */
    public function __construct(array $relations = [])
    {
        $this->relations = $relations;
    }

    /**
     * Get a specific relation by name.
     */
    public function get(string $name): ?RelationDescriptor
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Check if a relation exists.
     */
    public function has(string $name): bool
    {
        return isset($this->relations[$name]);
    }

    /**
     * Get all relations.
     *
     * @return array<string, RelationDescriptor>
     */
    public function all(): array
    {
        return $this->relations;
    }

    /**
     * Get all relation names.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->relations);
    }

    /**
     * Iterate over relations.
     */
    public function forEach(\Closure $callback): void
    {
        foreach ($this->relations as $name => $descriptor) {
            $callback($name, $descriptor);
        }
    }

    /**
     * Get relations of a specific type.
     *
     * @return array<string, RelationDescriptor>
     */
    public function ofType(RelationType $type): array
    {
        return array_filter(
            $this->relations,
            fn ($descriptor) => $descriptor->type === $type
        );
    }

    /**
     * Count relations.
     */
    public function count(): int
    {
        return count($this->relations);
    }

    /**
     * Check if graph is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->relations);
    }
}
