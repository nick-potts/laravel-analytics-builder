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
        // Get driver name from grammar class name (e.g., MySqlGrammar -> mysql)
        $grammarClass = class_basename($grammar);
        $driver = strtolower(str_replace('Grammar', '', $grammarClass));
        if ($driver === 'sqlite') {
            $driver = 'sqlite';
        } elseif (str_starts_with($driver, 'mysql')) {
            $driver = 'mysql';
        } elseif (str_starts_with($driver, 'postgres')) {
            $driver = 'pgsql';
        }

        if (! isset(static::$compilers[$aggregationClass])) {
            throw new \RuntimeException(
                "No compiler registered for aggregation: {$aggregationClass}"
            );
        }

        $driverCompilers = static::$compilers[$aggregationClass];

        if (! isset($driverCompilers[$driver])) {
            throw new \RuntimeException(
                "No compiler registered for aggregation {$aggregationClass} on driver: {$driver}"
            );
        }

        $compiler = $driverCompilers[$driver];

        return $compiler($aggregation, $grammar);
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
