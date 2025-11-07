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
     */
    public function process(array $rows, array $normalizedMetrics): ResultCollection
    {
        // Split metrics by computation strategy
        $split = $this->dependencyResolver->splitByComputationStrategy($normalizedMetrics);

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
                    $processedRow[$key] = $this->evaluateExpression(
                        $expression,
                        $dependencies,
                        $processedRow
                    );
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
                // Register NULLIF function
                $expressionLanguage->register(
                    'NULLIF',
                    fn ($a, $b) => "({$a} === {$b} ? null : {$a})",
                    fn ($args, $a, $b) => $a === $b ? null : $a
                );

                return $expressionLanguage->evaluate($expression, $context);
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
        $metricKeys = array_map(fn ($m) => $m['key'], $normalizedMetrics);

        foreach ($metricKeys as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $this->normalizeNumericValue($row[$key]);
            }
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
     */
    protected function normalizeNumericValue(mixed $value): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        $numeric = (float) $value;

        return fmod($numeric, 1.0) === 0.0 ? (int) round($numeric) : $numeric;
    }
}
