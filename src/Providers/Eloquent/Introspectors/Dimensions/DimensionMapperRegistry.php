<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use RuntimeException;

/**
 * Registry for managing DimensionMapper implementations.
 *
 * Maintains a map of cast types to mappers. Each cast type can only be
 * handled by one mapper. Attempting to register a mapper that handles a
 * cast type already handled by another mapper will throw an exception.
 */
class DimensionMapperRegistry
{
    /** @var array<string, DimensionMapper> Maps cast type to mapper */
    private array $handlers = [];

    /** @var array<DimensionMapper> Special mappers that check dynamically */
    private array $dynamicMappers = [];

    public function __construct()
    {
        // Register built-in mappers
        $this->register(new TimeDimensionMapper);

        // EnumDimensionMapper is special - it checks dynamically if a class is an enum
        $this->dynamicMappers[] = new EnumDimensionMapper;

        $this->register(new BooleanDimensionMapper);
        $this->register(new StringDimensionMapper);
    }

    /**
     * Register a mapper.
     *
     * @throws RuntimeException If any handled cast type is already registered
     */
    public function register(DimensionMapper $mapper): self
    {
        foreach ($mapper->handles() as $castType) {
            if (isset($this->handlers[$castType])) {
                throw new RuntimeException(
                    "Cast type '{$castType}' is already handled by "
                    .get_class($this->handlers[$castType]).'. '
                    .'Cannot register '.get_class($mapper).'.'
                );
            }

            $this->handlers[$castType] = $mapper;
        }

        return $this;
    }

    /**
     * Find a mapper for the given cast type.
     *
     * Uses pattern matching for cast types with custom formats (e.g., 'datetime:Y-m-d').
     * First checks for exact match, then tries pattern matching, then dynamic mappers.
     */
    public function getMapper(string $castType): ?DimensionMapper
    {
        // Direct lookup
        if (isset($this->handlers[$castType])) {
            return $this->handlers[$castType];
        }

        // Pattern matching for custom formats (e.g., 'datetime:Y-m-d' should match 'datetime')
        $baseCast = strtolower(strtok($castType, ':') ?: $castType);
        if ($baseCast !== $castType && isset($this->handlers[$baseCast])) {
            return $this->handlers[$baseCast];
        }

        // Check dynamic mappers (e.g., EnumDimensionMapper)
        foreach ($this->dynamicMappers as $mapper) {
            if ($mapper instanceof EnumDimensionMapper && $mapper->supportsEnum($castType)) {
                return $mapper;
            }
        }

        return null;
    }

    /**
     * Get all registered mappers.
     *
     * @return array<DimensionMapper>
     */
    public function all(): array
    {
        return array_merge(array_unique($this->handlers, SORT_REGULAR), $this->dynamicMappers);
    }
}
