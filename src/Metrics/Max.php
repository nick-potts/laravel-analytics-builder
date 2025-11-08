<?php

namespace NickPotts\Slice\Metrics;

class Max extends BaseAggregation
{
    public function aggregation(): string
    {
        return 'max';
    }
}
