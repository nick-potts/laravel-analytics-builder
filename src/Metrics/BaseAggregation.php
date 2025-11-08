<?php

namespace NickPotts\Slice\Metrics;

use InvalidArgumentException;
use NickPotts\Slice\Contracts\AggregationMetric;
use NickPotts\Slice\Contracts\QueryAdapter;

/**
 * Base class for aggregation metrics.
 * Provides common functionality for Sum, Count, Avg, Min, Max, etc.
 */
abstract class BaseAggregation implements AggregationMetric
{
    protected string $table;

    protected string $column;

    protected ?string $label = null;

    protected ?string $format = null;

    protected array $formatOptions = [];

    /**
     * Create a new aggregation metric.
     *
     * @param  string  $reference  Table.column notation (e.g., 'orders.total')
     */
    public function __construct(string $reference)
    {
        $this->parseReference($reference);
    }

    /**
     * Static factory method.
     *
     * @param  string  $reference  Table.column notation (e.g., 'orders.total')
     */
    public static function make(string $reference): static
    {
        return new static($reference);
    }

    /**
     * Parse table.column reference.
     */
    protected function parseReference(string $reference): void
    {
        if (! str_contains($reference, '.')) {
            throw new InvalidArgumentException(
                "Metric reference must be in 'table.column' format, got: {$reference}"
            );
        }

        [$table, $column] = explode('.', $reference, 2);

        $this->table = $table;
        $this->column = $column;
    }

    /**
     * Set a human-readable label.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Format as currency.
     */
    public function currency(string $currency = 'USD', int $decimals = 2): static
    {
        $this->format = 'currency';
        $this->formatOptions = [
            'currency' => $currency,
            'decimals' => $decimals,
        ];

        return $this;
    }

    /**
     * Format with decimal places.
     */
    public function decimals(int $decimals): static
    {
        $this->format = 'number';
        $this->formatOptions = ['decimals' => $decimals];

        return $this;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function column(): string
    {
        return $this->column;
    }

    /**
     * Get the aggregation type (implemented by subclasses).
     */
    abstract public function aggregation(): string;

    public function type(): string
    {
        return 'aggregation';
    }

    public function key(): string
    {
        return "{$this->table}_{$this->column}";
    }

    public function applyToQuery(QueryAdapter $query, string $alias): void
    {
        $aggregation = strtoupper($this->aggregation());
        $column = "{$this->table}.{$this->column}";

        $query->selectRaw("{$aggregation}({$column}) as {$alias}");
    }

    public function toArray(): array
    {
        return [
            'type' => 'aggregation',
            'key' => $this->key(),
            'table' => $this->table,
            'column' => $this->column,
            'aggregation' => $this->aggregation(),
            'label' => $this->label,
            'format' => $this->format,
            'formatOptions' => $this->formatOptions,
        ];
    }
}
