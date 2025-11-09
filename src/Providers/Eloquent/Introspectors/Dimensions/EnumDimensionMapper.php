<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Dimensions;

use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Schemas\Dimensions\EnumDimension;

/**
 * Maps PHP enum casts to EnumDimension instances.
 *
 * Handles Laravel enum casts: Any class that is a BackedEnum or Enum.
 * Extracts enum cases and creates an EnumDimension with the case information.
 */
class EnumDimensionMapper implements DimensionMapper
{
    public function handles(): array
    {
        // This mapper doesn't have a fixed list of cast types - it matches
        // any class name that is an enum. The registry will use getMapper()
        // to check if a cast type is an enum at runtime.
        return [];
    }

    /**
     * Check if the cast type is an enum class.
     *
     * Note: This is called by DimensionMapperRegistry::getMapper() after
     * checking fixed handlers, so enum classes won't be in the handlers map.
     */
    public function supportsEnum(string $castType): bool
    {
        return enum_exists($castType);
    }

    /**
     * @param  class-string<\BackedEnum>  $castType
     */
    public function map(string $column, string $castType): ?Dimension
    {
        if (! $this->supportsEnum($castType)) {
            return null;
        }

        $dimension = EnumDimension::make($column)
            ->withEnumClass($castType);

        // Extract enum cases
        try {
            $cases = $castType::cases();
            $caseMap = [];

            foreach ($cases as $case) {
                $caseMap[$case->name] = $case->value ?? $case->name;
            }

            $dimension = $dimension->withCases($castType::cases());
        } catch (\Throwable $e) {
            // If we can't extract cases, just create the dimension without them
            throw_if(app()->runningUnitTests(), $e);
        }

        return $dimension;
    }
}
