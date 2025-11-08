<?php

namespace NickPotts\Slice\Metrics;

class Min extends BaseAggregation
{
    public function aggregation(): string
    {
        return 'min';
    }
}
