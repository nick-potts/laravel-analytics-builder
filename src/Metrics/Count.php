<?php

namespace NickPotts\Slice\Metrics;

class Count extends BaseAggregation
{
    public function aggregation(): string
    {
        return 'count';
    }
}
