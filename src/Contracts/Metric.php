<?php

namespace NickPotts\Slice\Contracts;

/**
 * Contract for concrete metric implementations (Sum, Count, Avg, Computed, etc.).
 */
interface Metric
{
    /**
     * Get the metric name for use as a key.
     */
    public function key(): string;

    /**
     * Convert metric to array representation.
     */
    public function toArray(): array;
}
