<?php

namespace NickPotts\Slice\Metrics;

use Closure;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use NickPotts\Slice\Contracts\DatabaseMetric;
use NickPotts\Slice\Metrics\Concerns\HasLabel;
use NickPotts\Slice\Support\Registry;
use NickPotts\Slice\Tables\Table;

abstract class Aggregation implements DatabaseMetric
{
    use Conditionable;
    use HasLabel;
    use Macroable;

    protected string $column;

    protected string $tableName;

    protected string|Closure|null $label = null;

    protected string|Closure|null $currency = null;

    protected int|Closure|null $decimals = null;

    protected bool|Closure $percentage = false;

    /**
     * Create a new aggregation instance.
     */
    public static function make(string $column): static
    {
        $instance = new static($column);
        $instance->setUp();

        return $instance;
    }

    /**
     * Constructor.
     */
    public function __construct(string $column)
    {
        $this->parseColumn($column);
    }

    /**
     * Parse the column reference to extract table and column names.
     */
    protected function parseColumn(string $column): void
    {
        if (str_contains($column, '.')) {
            [$this->tableName, $this->column] = explode('.', $column, 2);
        } else {
            throw new \InvalidArgumentException("Column must be in 'table.column' format. Got: {$column}");
        }
    }

    /**
     * Hook for setting up default configuration.
     * Called automatically after construction via make().
     */
    protected function setUp(): void
    {
        $this->label = $this->generateLabel();
    }

    /**
     * Get the table instance for this metric.
     * Resolved from the table name parsed from 'table.column' format.
     */
    public function table(): Table
    {
        $table = app(Registry::class)->getTableByName($this->tableName);

        if (! $table) {
            throw new \RuntimeException("Table '{$this->tableName}' not found in registry. Did you register it?");
        }

        return $table;
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
     * Get the aggregation type (e.g., 'sum', 'avg').
     */
    abstract public function aggregationType(): string;

    /**
     * Get the column name.
     */
    public function column(): string
    {
        return $this->column;
    }

    /**
     * Get the table name.
     */
    public function tableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the metric name for use as a key.
     * Returns table_column format to ensure uniqueness.
     */
    public function key(): string
    {
        return $this->tableName.'_'.$this->column;
    }

    /**
     * Get the label, evaluating closures if necessary.
     */
    public function getLabel(): string
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
            'name' => $this->column,
            'type' => $this->aggregationType(),
            'column' => $this->column,
            'table' => $this->tableName,
            'label' => $this->getLabel(),
            'aggregations' => [$this->aggregationType()],
            'computed' => false,
            'dependencies' => [],
            'formatter' => $formatter,
            'format_options' => $formatOptions,
            'column_type' => $columnType,
        ];
    }
}
