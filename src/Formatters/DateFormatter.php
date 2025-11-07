<?php

namespace NickPotts\Slice\Formatters;

class DateFormatter
{
    public function format(string|\DateTimeInterface $value, string $format = 'Y-m-d'): string
    {
        $date = $value instanceof \DateTimeInterface
            ? $value
            : new \DateTimeImmutable($value);

        return $date->format($format);
    }
}
