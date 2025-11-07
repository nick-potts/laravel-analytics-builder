<?php

namespace NickPotts\Slice\Schemas;

class TimeDimension extends Dimension
{
    protected string $granularity = 'day';

    protected string $precision = 'timestamp';

    public static function make(string $name): static
    {
        $dimension = parent::make($name);
        $dimension->type('datetime');

        return $dimension;
    }

    public function granularity(string $granularity): static
    {
        $this->granularity = $granularity;

        return $this;
    }

    /**
     * Shorthand methods for common granularities.
     */
    public function hourly(): static
    {
        return $this->granularity('hour');
    }

    public function daily(): static
    {
        return $this->granularity('day');
    }

    public function weekly(): static
    {
        return $this->granularity('week');
    }

    public function monthly(): static
    {
        return $this->granularity('month');
    }

    public function yearly(): static
    {
        return $this->granularity('year');
    }

    /**
     * Specify that this dimension uses full timestamp precision.
     */
    public function asTimestamp(): static
    {
        $this->precision = 'timestamp';

        return $this;
    }

    /**
     * Specify that this dimension only has date precision (no time).
     */
    public function asDate(): static
    {
        $this->precision = 'date';

        return $this;
    }

    /**
     * Get the configured precision.
     */
    public function precision(): string
    {
        return $this->precision;
    }

    /**
     * Get the current granularity.
     */
    public function getGranularity(): string
    {
        return $this->granularity;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'granularity' => $this->granularity,
            'precision' => $this->precision,
        ]);
    }
}
