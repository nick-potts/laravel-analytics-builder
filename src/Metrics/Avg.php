<?php

namespace NickPotts\Slice\Metrics;

class Avg extends BaseAggregation
{
    public function aggregation(): string
    {
        return 'avg';
    }
}
