<?php

namespace NickPotts\Slice\Contracts;

/**
 * Trait for metric enums that act as shortcuts to concrete metric instances.
 * Provides no implementation - enums only need to implement table() and get().
 */
trait EnumMetric
{
    // No methods needed - enums just return concrete Metric instances via get()
}
