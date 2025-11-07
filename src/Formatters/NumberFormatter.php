<?php

namespace NickPotts\Slice\Formatters;

class NumberFormatter
{
    public function format(float|int $value, int $precision = 2): string
    {
        return number_format($value, $precision);
    }
}
