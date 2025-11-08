<?php

namespace NickPotts\Slice\Metrics;

use Closure;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use NickPotts\Slice\Contracts\Metric;
use NickPotts\Slice\Contracts\MetricContract;
use NickPotts\Slice\Tables\Table;

class Computed implements Metric
{
    use Conditionable;
    use Macroable;

    protected string $expression;

    protected array $dependencies = [];

    protected string|Closure|null $label = null;

    protected string|Closure|null $currency = null;

    protected int|Closure|null $decimals = null;

    protected bool|Closure $percentage = false;

    protected ?Table $table = null;

    /**
     * Create a new computed metric.
     *
     * @return static
     */
    public static function make(string $expression): static
    {
        $instance = new static;
        $instance->expression = $expression;
        $instance->setUp();

        return $instance;
    }

    /**
     * Hook for setting up defaults.
     */
    protected function setUp(): void
    {
        $this->decimals = 2;
    }

    /**
     * Set a custom label for this metric.
     */
    public function label(string|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Specify dependencies for this computed metric.
     */
    public function dependsOn(Metric|MetricContract|string ...$metrics): static
    {
        foreach ($metrics as $metric) {
            if ($metric instanceof Metric) {
                $this->dependencies[] = $metric->key();
            } elseif ($metric instanceof MetricContract) {
                $metricDef = $metric->get();
                $this->dependencies[] = $metricDef->key();
            } else {
                $this->dependencies[] = $metric;
            }
        }

        return $this;
    }

    /**
     * Format as currency.
     */
    public function currency(string|Closure $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Set decimal precision.
     */
    public function decimals(int|Closure $decimals): static
    {
        $this->decimals = $decimals;

        return $this;
    }

    /**
     * Format as percentage.
     */
    public function percentage(bool|Closure $percentage = true): static
    {
        $this->percentage = $percentage;

        return $this;
    }

    /**
     * Specify which table this computed metric belongs to.
     */
    public function forTable(Table $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Get the table instance.
     */
    public function table(): Table
    {
        if (! $this->table) {
            throw new \RuntimeException('Computed metrics must specify a table via forTable() or be used in enum context');
        }

        return $this->table;
    }

    /**
     * Get the metric name for use as a key.
     */
    public function key(): string
    {
        return 'computed_'.md5($this->expression);
    }

    /**
     * Get the label, evaluating closures if necessary.
     */
    public function getLabel(): ?string
    {
        return $this->evaluate($this->label);
    }

    /**
     * Get the currency, evaluating closures if necessary.
     */
    public function getCurrency(): ?string
    {
        return $this->evaluate($this->currency);
    }

    /**
     * Get the decimal precision, evaluating closures if necessary.
     */
    public function getDecimals(): ?int
    {
        return $this->evaluate($this->decimals);
    }

    /**
     * Check if this metric should be formatted as a percentage.
     */
    public function isPercentage(): bool
    {
        return (bool) $this->evaluate($this->percentage);
    }

    /**
     * Evaluate a value, calling it if it's a closure.
     */
    protected function evaluate(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            return $value($this);
        }

        return $value;
    }

    /**
     * Convert metric to array representation.
     */
    public function toArray(): array
    {
        $currency = $this->getCurrency();
        $decimals = $this->getDecimals();
        $isPercentage = $this->isPercentage();

        // Determine formatter and format options
        $formatter = null;
        $formatOptions = [];
        $columnType = null;

        if ($currency) {
            $formatter = 'currency';
            $columnType = 'money';
            $formatOptions = [
                'currency' => $currency,
                'precision' => $decimals ?? 2,
            ];
        } elseif ($isPercentage) {
            $formatter = 'percentage';
            $columnType = 'percentage';
            $formatOptions = ['precision' => $decimals ?? 2];
        } elseif ($decimals !== null) {
            $formatter = 'number';
            $columnType = 'decimal';
            $formatOptions = ['precision' => $decimals];
        }

        return [
            'name' => $this->key(),
            'label' => $this->getLabel() ?? $this->key(),
            'column' => null,
            'expression' => $this->expression,
            'computed' => true,
            'aggregations' => [],
            'dependencies' => $this->dependencies,
            'formatter' => $formatter,
            'format_options' => $formatOptions,
            'column_type' => $columnType,
        ];
    }
}
