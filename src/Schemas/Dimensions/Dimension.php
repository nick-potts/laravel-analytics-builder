<?php

namespace NickPotts\Slice\Schemas\Dimensions;

/**
 * Base interface for dimensions.
 *
 * A dimension is a way to slice/group analytics data.
 * Examples: time (hourly, daily, monthly), country, product category.
 *
 * Implementations are discovered automatically by providers or
 * registered manually in table definitions.
 */
interface Dimension
{
    /**
     * Get the dimension name/key.
     */
    public function name(): string;

    /**
     * Get the column this dimension operates on.
     */
    public function column(): string;
}
