<?php

namespace NickPotts\Slice\Schemas\Dimensions;

/**
 * Time/date dimension for grouping analytics data by time periods.
 *
 * Supports various granularities (hourly, daily, weekly, monthly, yearly)
 * and is auto-discovered from Eloquent model datetime casts.
 */
class TimeDimension implements Dimension
{
    /**
     * Granularity options.
     */
    final public const HOURLY = 'hour';

    final public const DAILY = 'day';

    final public const WEEKLY = 'week';

    final public const MONTHLY = 'month';

    final public const YEARLY = 'year';

    private string $granularity = self::DAILY;

    private string $precision = 'timestamp'; // 'timestamp' or 'date'

    private function __construct(
        private readonly string $column,
        private readonly ?string $name = null,
    ) {}

    public static function make(string $column): self
    {
        return new self($column);
    }

    public function name(): string
    {
        return $this->name ?? $this->column;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function granularity(): string
    {
        return $this->granularity;
    }

    public function precision(): string
    {
        return $this->precision;
    }

    /**
     * Set the time granularity.
     */
    public function setGranularity(string $granularity): self
    {
        $this->granularity = $granularity;

        return $this;
    }

    /**
     * Set the column precision (date vs timestamp).
     */
    public function precision(string $precision): self
    {
        $this->precision = $precision;

        return $this;
    }

    /**
     * Set hourly granularity.
     */
    public function hourly(): self
    {
        $this->granularity = self::HOURLY;

        return $this;
    }

    /**
     * Set daily granularity.
     */
    public function daily(): self
    {
        $this->granularity = self::DAILY;

        return $this;
    }

    /**
     * Set weekly granularity.
     */
    public function weekly(): self
    {
        $this->granularity = self::WEEKLY;

        return $this;
    }

    /**
     * Set monthly granularity.
     */
    public function monthly(): self
    {
        $this->granularity = self::MONTHLY;

        return $this;
    }

    /**
     * Set yearly granularity.
     */
    public function yearly(): self
    {
        $this->granularity = self::YEARLY;

        return $this;
    }
}
