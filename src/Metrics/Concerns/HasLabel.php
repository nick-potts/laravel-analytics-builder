<?php

namespace NickPotts\Slice\Metrics\Concerns;

use Illuminate\Support\Str;

trait HasLabel
{
    /**
     * Generate a human-readable label from the column name.
     *
     * Examples:
     *   'orders.total' → 'Total'
     *   'ad_spend.impressions' → 'Impressions'
     *   'customer_id' → 'Customer ID'
     */
    protected function generateLabel(): string
    {
        return Str::of($this->column)
            ->replace('_', ' ')
            ->title()
            ->toString();
    }
}
