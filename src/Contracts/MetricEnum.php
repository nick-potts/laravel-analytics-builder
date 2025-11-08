<?php

namespace NickPotts\Slice\Contracts;

use NickPotts\Slice\Tables\Table;

/**
 * Contract for metric enums that reference a table.
 * Extends MetricContract to add the table() method required by enum-based metrics.
 */
interface MetricEnum extends MetricContract
{
    /**
     * Get the table this metric belongs to.
     */
    public function table(): Table;
}
