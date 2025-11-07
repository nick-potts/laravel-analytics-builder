<?php

namespace NickPotts\Slice\Formatters;

use Illuminate\Support\Number;

class CurrencyFormatter
{
    public function format(int|float $number, string $in = '', ?string $locale = null, ?int $precision = null): string
    {
        $result = Number::currency($number, $in, $locale, $precision);
        assert($result !== false, new CurrencyException('Unable to format currency for currency code '));

        return $result;
    }
}
