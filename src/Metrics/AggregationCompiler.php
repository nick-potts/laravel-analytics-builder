<?php

namespace NickPotts\Slice\Metrics;

use Illuminate\Database\Query\Grammars\Grammar;
use NickPotts\Slice\Metrics\Aggregations\Aggregation;

class AggregationCompiler
{
    /**
     * Compilers for aggregations with optional default and driver-specific overrides
     *
     * Structure:
     * [
     *   'AggregationClassName' => [
     *     'default' => fn($agg, $grammar) => "...",
     *     'mysql' => fn($agg, $grammar) => "...",  // optional driver override
     *     'pgsql' => fn($agg, $grammar) => "...",  // optional driver override
     *   ]
     * ]
     */
    protected static array $compilers = [];

    /**
     * Register aggregation compilers with optional default and driver-specific overrides
     *
     * @param  class-string<Aggregation>  $aggregationClass
     * @param  array<string, callable>  $compilers  Map of 'default' and optional driver names => compiler functions
     */
    public static function register(string $aggregationClass, array $compilers): void
    {
        static::$compilers[$aggregationClass] = $compilers;
    }

    /**
     * Compile an aggregation to SQL using the given grammar
     *
     * Tries driver-specific compiler first, then falls back to default.
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

        $compilers = static::$compilers[$aggregationClass];

        if (! isset($compilers['default'])) {
            $aggregationName = class_basename($aggregationClass);
            throw new \RuntimeException(
                "Aggregation '{$aggregationName}' must define a 'default' compiler."
            );
        }

        // Check for driver-specific override first, then fall back to default
        $compiler = $compilers[$driver] ?? $compilers['default'];

        return $compiler($aggregation, $grammar);
    }

    /**
     * Get driver name from Grammar class
     *
     * Driver name is derived from the Grammar class name by removing 'Grammar' suffix.
     * Example: MySqlGrammar -> mysql, PostgresGrammar -> postgres
     *
     * When compiling, the system first checks for a driver-specific compiler,
     * then falls back to the default compiler. This allows third-party packages
     * to add driver-specific optimizations by registering driver overrides
     * without requiring core aggregation classes to know about every driver.
     *
     * Example: A MongoDB package would do:
     *   AggregationCompiler::register(Sum::class, [
     *       'default' => [...],
     *       'mongo' => fn($agg, $grammar) => 'sum(...)'
     *   ]);
     */
    private static function normalizeDriverName(Grammar $grammar): string
    {
        $grammarClass = class_basename($grammar);

        return strtolower(str_replace('Grammar', '', $grammarClass));
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
