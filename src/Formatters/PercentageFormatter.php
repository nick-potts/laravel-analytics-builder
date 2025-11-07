<?php

namespace NickPotts\Slice\Formatters;

class PercentageFormatter
{
    public function format(float $value, int $precision = 2): string
    {
        return number_format($value * 100, $precision) . '%';
    }
}
