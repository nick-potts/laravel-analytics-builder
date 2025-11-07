<?php

namespace NickPotts\Slice\Metrics;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;

class Percentile extends Aggregation
{
    protected float $percentile = 0.5; // Default to median (50th percentile)

    /**
     * Custom percentile compilers for different database drivers.
     * Plugins can register their own compilers here.
     *
     * @var array<string, callable>
     */
    protected static array $compilers = [];

    /**
     * Set up default configuration for Percentile aggregation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Percentiles typically need decimal precision
        $this->decimals = 2;
    }

    /**
     * Set the percentile value (0.0 to 1.0).
     * Common values: 0.5 (median), 0.75 (75th), 0.90 (90th), 0.95 (95th), 0.99 (99th)
     */
    public function percentile(float $percentile): static
    {
        if ($percentile < 0 || $percentile > 1) {
            throw new \InvalidArgumentException("Percentile must be between 0 and 1. Got: {$percentile}");
        }

        $this->percentile = $percentile;

        return $this;
    }

    /**
     * Convenience method to set median (50th percentile).
     */
    public function median(): static
    {
        return $this->percentile(0.5);
    }

    /**
     * Get the configured percentile value.
     */
    public function getPercentile(): float
    {
        return $this->percentile;
    }

    /**
     * Get the aggregation type.
     */
    public function aggregationType(): string
    {
        return 'percentile';
    }

    /**
     * Apply PERCENTILE aggregation to the query.
     * Uses database-specific implementations for portability.
     */
    public function applyToQuery(QueryAdapter $query, QueryDriver $driver, string $tableName, string $alias): void
    {
        $percentile = $this->percentile;
        $column = $this->column;
        $driverName = $driver->name();

        // Build database-specific SQL
        $sql = $this->buildPercentileSql($driverName, $tableName, $column, $percentile);

        $query->selectRaw("{$sql} as {$alias}");
    }

    /**
     * Register a custom percentile compiler for a database driver.
     * This allows plugins to add support for additional databases.
     *
     * Example:
     *   Percentile::compiler('singlestore', function($tableName, $column, $percentile) {
     *       return "APPROX_PERCENTILE({$tableName}.{$column}, {$percentile})";
     *   });
     */
    public static function compiler(string $driver, callable $callback): void
    {
        static::$compilers[$driver] = $callback;
    }

    /**
     * Build database-specific percentile SQL.
     * Checks for custom compilers first, then falls back to built-in support.
     */
    protected function buildPercentileSql(string $driver, string $tableName, string $column, float $percentile): string
    {
        // Check for custom compiler first (allows plugins to override)
        if (isset(static::$compilers[$driver])) {
            return call_user_func(static::$compilers[$driver], $tableName, $column, $percentile);
        }

        // Fall back to built-in support
        return match ($driver) {
            // PostgreSQL has native percentile support
            'pgsql' => "PERCENTILE_CONT({$percentile}) WITHIN GROUP (ORDER BY {$tableName}.{$column})",

            // MySQL 8.0+ using window functions
            'mysql' => "JSON_EXTRACT(JSON_ARRAYAGG({$tableName}.{$column} ORDER BY {$tableName}.{$column}), CONCAT('$[', FLOOR({$percentile} * (COUNT(*) - 1)), ']'))",

            // SQLite using subquery
            'sqlite' => "(SELECT {$column} FROM {$tableName} ORDER BY {$column} LIMIT 1 OFFSET CAST({$percentile} * (SELECT COUNT(*) FROM {$tableName}) AS INTEGER))",

            default => throw new \RuntimeException("Percentile aggregation not supported for database driver: {$driver}. Register a custom compiler using Percentile::compiler('{$driver}', callable)."),
        };
    }

    /**
     * Convert metric to array representation.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // Add percentile-specific metadata
        $array['percentile'] = $this->percentile;
        $array['type'] = 'percentile';

        return $array;
    }
}
