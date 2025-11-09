<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Casts;

/**
 * Information about a single Eloquent model cast.
 */
class CastInfo
{
    public function __construct(
        public readonly string $column,
        public readonly string $castType,
        public readonly bool $isEnum = false,
        public readonly bool $isCustom = false,
    ) {}
}
