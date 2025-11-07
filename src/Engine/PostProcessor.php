<?php

namespace NickPotts\Slice\Engine;

class PostProcessor
{
    /**
     * Process query results and calculate computed metrics.
     *
     * @param  array  $rows  Raw query results
     * @param  array  $normalizedMetrics  Normalized metrics from Slice
     */
    public function process(array $rows, array $normalizedMetrics): ResultCollection
    {
        // For now, just return results as-is
        // Computed metrics would be calculated here by evaluating their expressions
        // using the values from other metrics in each row

        // Extract metric keys for type normalization
        $metricKeys = array_map(fn ($m) => $m['key'], $normalizedMetrics);

        $processedRows = [];

        foreach ($rows as $row) {
            $rowArray = (array) $row;
            $processedRow = $rowArray;

            // Normalize metric values to proper numeric types (int/float instead of string)
            // This ensures database query results match software join results
            foreach ($metricKeys as $key) {
                if (array_key_exists($key, $processedRow)) {
                    $processedRow[$key] = $this->normalizeNumericValue($processedRow[$key]);
                }
            }

            // Calculate computed metrics
            foreach ($normalizedMetrics as $metricData) {
                $metricArray = $metricData['metric']->toArray();

                if ($metricArray['computed']) {
                    $key = $metricData['key'];
                    $expression = $metricArray['expression'];
                    $dependencies = $metricArray['dependencies'];

                    // Evaluate expression using dependency values
                    // For now, we'll skip this and let computed metrics be null
                    // A full implementation would parse the expression and calculate the value
                    $processedRow[$key] = $this->evaluateExpression($expression, $dependencies, $processedRow);
                }
            }

            $processedRows[] = $processedRow;
        }

        return new ResultCollection($processedRows);
    }

    /**
     * Normalize a numeric value from database to proper PHP type.
     * Converts string numbers to int or float as appropriate.
     */
    protected function normalizeNumericValue(mixed $value): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        $numeric = (float) $value;

        // Return int if the value has no decimal part, otherwise return float
        return fmod($numeric, 1.0) === 0.0 ? (int) round($numeric) : $numeric;
    }

    /**
     * Evaluate a computed metric expression.
     * This is a simplified implementation - a full version would use an expression parser.
     */
    protected function evaluateExpression(string $expression, array $dependencies, array $row): ?float
    {
        // For now, return null for computed metrics
        // A full implementation would:
        // 1. Parse the expression (e.g., "revenue - item_cost")
        // 2. Replace metric keys with actual values from $row
        // 3. Safely evaluate the mathematical expression
        // 4. Return the computed value

        return null;
    }
}
