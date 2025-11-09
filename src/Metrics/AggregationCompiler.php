<?php

namespace NickPotts\Slice\Metrics;

use Illuminate\Database\Query\Grammars\Grammar;
use NickPotts\Slice\Metrics\Aggregations\Aggregation;

class AggregationCompiler
{
    /**
     * Driver-specific compilers for aggregations
     *
     * Structure:
     * [
     *   'AggregationClassName' => [
     *     'mysql' => fn($agg, $grammar) => "...",
     *     'pgsql' => fn($agg, $grammar) => "...",
     *   ]
     * ]
     */
    protected static array $compilers = [];

    /**
     * Register aggregation compilers for specific drivers
     *
     * @param  class-string<Aggregation>  $aggregationClass
     * @param  array<string, callable>  $driverCompilers  Map of driver => compiler function
     */
    public static function register(string $aggregationClass, array $driverCompilers): void
    {
        static::$compilers[$aggregationClass] = $driverCompilers;
    }

    /**
     * Compile an aggregation to SQL using the given grammar
     */
    public static function compile(Aggregation $aggregation, Grammar $grammar): string
    {
        $aggregationClass = get_class($aggregation);
        $driver = static::normalizeDriverName($grammar);

        if (! isset(static::$compilers[$aggregationClass])) {
            $aggregationName = class_basename($aggregationClass);
            throw new \RuntimeException(
                "Aggregation '{$aggregationName}' is not registered. "
                .'Make sure the aggregation class is loaded and its compilers are registered in the service provider.'
            );
        }

        $driverCompilers = static::$compilers[$aggregationClass];

        if (! isset($driverCompilers[$driver])) {
            $aggregationName = class_basename($aggregationClass);
            $supportedDrivers = implode(', ', array_keys($driverCompilers));
            throw new \RuntimeException(
                "Aggregation '{$aggregationName}' does not support driver '{$driver}'. "
                ."Supported drivers: {$supportedDrivers}."
            );
        }

        $compiler = $driverCompilers[$driver];

        return $compiler($aggregation, $grammar);
    }

    /**
     * Normalize driver name from Grammar class or actual connection driver
     */
    private static function normalizeDriverName(Grammar $grammar): string
    {
        $grammarClass = class_basename($grammar);
        $driver = strtolower(str_replace('Grammar', '', $grammarClass));

        // Map driver names to canonical names
        if ($driver === 'sqlite') {
            return 'sqlite';
        } elseif (str_starts_with($driver, 'mysql') || $driver === 'mariadb') {
            return 'mysql';
        } elseif (str_starts_with($driver, 'postgres')) {
            return 'pgsql';
        }

        return $driver;
    }

    /**
     * Get all registered compilers (for testing/debugging)
     */
    public static function getCompilers(): array
    {
        return static::$compilers;
    }

    /**
     * Clear all compilers (for testing)
     */
    public static function reset(): void
    {
        static::$compilers = [];
    }
}
