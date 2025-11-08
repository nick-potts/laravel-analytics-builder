<?php

namespace NickPotts\Slice\Engine;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class PostProcessor
{
    public function __construct(
        protected ?DependencyResolver $dependencyResolver = null
    ) {
        $this->dependencyResolver ??= new DependencyResolver;
    }

    /**
     * Process query results and calculate computed metrics.
     * Supports both single-row and multi-row results with software CTEs.
     *
     * @param  bool  $forceSoftwareComputation  When true, computed metrics that would normally run
     *                                          in the database are calculated during post-processing.
     */
    public function process(array $rows, array $normalizedMetrics, bool $forceSoftwareComputation = false): ResultCollection
    {
        // Split metrics by computation strategy
        $split = $this->dependencyResolver->splitByComputationStrategy($normalizedMetrics);

        if ($forceSoftwareComputation) {
            [$split['database'], $promoted] = $this->promoteDatabaseComputedMetrics($split['database']);
            $split['software'] = array_merge($split['software'], $promoted);
        }

        if (! empty($rows)) {
            [$split['database'], $missingColumns] = $this->promoteMissingComputedMetrics($split['database'], $rows);
            $split['software'] = array_merge($split['software'], $missingColumns);
        }

        // If no software-computed metrics, just normalize and return
        if (empty($split['software'])) {
            return new ResultCollection($this->normalizeMetricValues($rows, $normalizedMetrics));
        }

        // Group software metrics by dependency level
        $softwareLevels = $this->dependencyResolver->groupByLevel($split['software']);

        // Process each row through the software CTE layers
        $processedRows = [];
        foreach ($rows as $row) {
            $processedRows[] = $this->processRowThroughSoftwareCTEs(
                (array) $row,
                $softwareLevels,
                $normalizedMetrics
            );
        }

        return new ResultCollection($processedRows);
    }

    /**
     * Process a single row through software CTE layers.
     * Each level builds on the previous level's computed values.
     */
    protected function processRowThroughSoftwareCTEs(
        array $row,
        array $softwareLevels,
        array $allMetrics
    ): array {
        $processedRow = $row;

        // Normalize base metric values first
        $processedRow = $this->normalizeRowMetrics($processedRow, $allMetrics);

        // Process each level sequentially (like CTEs)
        foreach ($softwareLevels as $level => $levelMetrics) {
            foreach ($levelMetrics as $metricData) {
                $key = $metricData['key'];
                $metricArray = $metricData['metric']->toArray();

                if ($metricArray['computed']) {
                    $expression = $metricArray['expression'];
                    $dependencies = $metricArray['dependencies'];

                    // Evaluate expression using current row data
                    $value = $this->evaluateExpression(
                        $expression,
                        $dependencies,
                        $processedRow
                    );

                    $processedRow[$key] = $this->normalizeComputedMetricValue($value, $expression);
                }
            }
        }

        return $processedRow;
    }

    /**
     * Evaluate a computed metric expression using row data.
     */
    protected function evaluateExpression(string $expression, array $dependencies, array $row): mixed
    {
        // Build a safe execution context with only the dependency values
        $context = [];
        foreach ($dependencies as $depKey) {
            if (! array_key_exists($depKey, $row)) {
                // Dependency not available, return null
                return null;
            }

            // Make dependency available in expression
            // e.g., "orders.revenue" becomes variable $orders_revenue
            $varName = str_replace('.', '_', $depKey);
            $context[$varName] = $row[$depKey];
        }

        // Translate expression to use variable names
        $translatedExpression = $this->translateExpression($expression, $dependencies);

        // Safely evaluate the expression
        return $this->safeEvaluate($translatedExpression, $context);
    }

    /**
     * Translate metric keys in expression to variable names.
     */
    protected function translateExpression(string $expression, array $dependencies): string
    {
        $translated = $expression;

        foreach ($dependencies as $depKey) {
            $varName = str_replace('.', '_', $depKey);

            // Replace metric key with variable name (handle word boundaries)
            $translated = preg_replace(
                '/\b'.preg_quote($depKey, '/').'\b/',
                $varName,
                $translated
            );
        }

        return $translated;
    }

    /**
     * Safely evaluate an expression with given context.
     * Uses symfony/expression-language for security.
     */
    protected function safeEvaluate(string $expression, array $context): mixed
    {
        // Option 1: Use Symfony Expression Language (recommended)
        if (class_exists(ExpressionLanguage::class)) {
            $expressionLanguage = new ExpressionLanguage;

            try {
                // Pre-process division with NULLIF: X / NULLIF(Y, Z) => (Y == Z ? null : X / Y)
                $expression = preg_replace_callback(
                    '/([^\s\/]+)\s*\/\s*NULLIF\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i',
                    fn ($matches) => "(({$matches[2]}) == ({$matches[3]}) ? null : ({$matches[1]}) / ({$matches[2]}))",
                    $expression
                );

                // Also handle standalone NULLIF (not in division)
                $expression = preg_replace_callback(
                    '/NULLIF\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i',
                    fn ($matches) => "(({$matches[1]}) == ({$matches[2]}) ? null : ({$matches[1]}))",
                    $expression
                );

                $result = $expressionLanguage->evaluate($expression, $context);

                // Cast to float if it's a numeric operation (division always returns float)
                if (is_numeric($result) && ! is_float($result)) {
                    return (float) $result;
                }

                return $result;
            } catch (\Exception $e) {
                // Expression evaluation failed
                return null;
            }
        }

        // Option 2: Simple math expression parser (fallback)
        return $this->evaluateSimpleMathExpression($expression, $context);
    }

    /**
     * Evaluate simple math expressions without eval().
     * Supports: +, -, *, /, (), NULLIF, basic functions.
     */
    protected function evaluateSimpleMathExpression(string $expression, array $context): mixed
    {
        // Replace variables with their values
        foreach ($context as $var => $value) {
            $expression = str_replace($var, (string) $value, $expression);
        }

        // Handle NULLIF(a, b) - returns null if a == b, else returns a
        $expression = preg_replace_callback(
            '/NULLIF\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i',
            function ($matches) {
                $a = trim($matches[1]);
                $b = trim($matches[2]);

                // Evaluate both sides
                $aVal = is_numeric($a) ? (float) $a : null;
                $bVal = is_numeric($b) ? (float) $b : null;

                if ($aVal === $bVal) {
                    return 'NULL';
                }

                return (string) $aVal;
            },
            $expression
        );

        // Handle NULL in expression
        if (stripos($expression, 'NULL') !== false) {
            return null;
        }

        // Try to evaluate as PHP math (safe subset)
        try {
            // Remove any non-math characters for safety
            $sanitized = preg_replace('/[^0-9+\-*\/().\s]/', '', $expression);

            if ($sanitized !== $expression) {
                // Contains unsafe characters, don't evaluate
                return null;
            }

            // Evaluate using a safe math evaluator
            return $this->evaluateMath($sanitized);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Safe math evaluator using bc_math or simple evaluation.
     */
    protected function evaluateMath(string $expression): float|int|null
    {
        // Validate the expression only contains safe characters
        if (! preg_match('/^[0-9+\-*\/().\s]+$/', $expression)) {
            return null;
        }

        try {
            // Create a closure to safely evaluate
            $evaluate = fn () => eval("return {$expression};");

            return $evaluate();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalize numeric values in a row.
     */
    protected function normalizeRowMetrics(array $row, array $normalizedMetrics): array
    {
        foreach ($normalizedMetrics as $metricData) {
            $key = $metricData['key'];

            if (! array_key_exists($key, $row)) {
                continue;
            }

            // Check if this is a computed metric that likely produces decimals
            $metricArray = $metricData['metric']->toArray();
            $isComputed = $metricArray['computed'] ?? false;
            $expression = $metricArray['expression'] ?? '';

            // Only preserve float for computed metrics that involve division
            // (which is likely to produce decimal results)
            $hasDivision = $isComputed && str_contains($expression, '/');

            $row[$key] = $this->normalizeNumericValue($row[$key], $hasDivision);
        }

        return $row;
    }

    /**
     * Normalize all rows.
     */
    protected function normalizeMetricValues(array $rows, array $normalizedMetrics): array
    {
        return array_map(
            fn ($row) => $this->normalizeRowMetrics((array) $row, $normalizedMetrics),
            $rows
        );
    }

    /**
     * Normalize a numeric value from database to proper PHP type.
     *
     * @param  bool  $preserveFloat  If true, preserve float type even for whole numbers
     */
    protected function normalizeNumericValue(mixed $value, bool $preserveFloat = false): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        $numeric = (float) $value;

        // Preserve float type for computed metrics or metrics with decimal formatting
        if ($preserveFloat) {
            return $numeric;
        }

        return fmod($numeric, 1.0) === 0.0 ? (int) round($numeric) : $numeric;
    }

    protected function normalizeComputedMetricValue(mixed $value, string $expression): mixed
    {
        $hasDivision = str_contains($expression, '/');

        return $this->normalizeNumericValue($value, $hasDivision);
    }

    /**
     * Promote computed metrics from the database bucket so they can be evaluated in software.
     *
     * @param  array  $databaseMetrics
     * @return array{0: array, 1: array}
     */
    protected function promoteDatabaseComputedMetrics(array $databaseMetrics): array
    {
        $remaining = [];
        $promoted = [];

        foreach ($databaseMetrics as $metricData) {
            $metricArray = $metricData['metric']->toArray();

            if ($metricArray['computed'] ?? false) {
                $promoted[] = $metricData;
                continue;
            }

            $remaining[] = $metricData;
        }

        return [$remaining, $promoted];
    }

    /**
     * Promote computed metrics whose columns are missing from the row data.
     *
     * @param  array  $databaseMetrics
     * @param  array  $rows
     * @return array{0: array, 1: array}
     */
    protected function promoteMissingComputedMetrics(array $databaseMetrics, array $rows): array
    {
        if (empty($databaseMetrics) || empty($rows)) {
            return [$databaseMetrics, []];
        }

        $firstRow = (array) ($rows[0] ?? []);

        $remaining = [];
        $promoted = [];

        foreach ($databaseMetrics as $metricData) {
            $metricArray = $metricData['metric']->toArray();
            $key = $metricData['key'];

            if (($metricArray['computed'] ?? false) && ! array_key_exists($key, $firstRow)) {
                $promoted[] = $metricData;

                continue;
            }

            $remaining[] = $metricData;
        }

        return [$remaining, $promoted];
    }
}
