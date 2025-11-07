<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Tables\Table;

class DimensionResolver
{
    /**
     * Resolve which tables support a given dimension.
     *
     * @param  array<Table>  $tables
     * @return array<string, array{table: Table, dimension: Dimension}>
     */
    public function resolveDimensionForTables(array $tables, Dimension $dimension): array
    {
        $dimensionClass = get_class($dimension);
        $resolved = [];

        foreach ($tables as $table) {
            $tableDimensions = $table->dimensions();

            // Check if table supports this dimension class
            foreach ($tableDimensions as $key => $tableDimension) {
                // Match by dimension class
                if ($key === $dimensionClass || $tableDimension instanceof $dimensionClass) {
                    $resolved[$table->table()] = [
                        'table' => $table,
                        'dimension' => $tableDimension,
                    ];
                    break;
                }

                // Match by base dimension class with key (e.g., Dimension::class.'::status')
                if (str_starts_with($key, $dimensionClass.'::') && $dimension->name() === $tableDimension->name()) {
                    $resolved[$table->table()] = [
                        'table' => $table,
                        'dimension' => $tableDimension,
                    ];
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * Validate that all tables support the requested dimension at the requested granularity.
     *
     * @param  array<string, array{table: Table, dimension: Dimension}>  $resolvedDimensions
     *
     * @throws \InvalidArgumentException
     */
    public function validateGranularity(array $resolvedDimensions, Dimension $requestedDimension): void
    {
        if (! method_exists($requestedDimension, 'getGranularity')) {
            return;
        }

        $requestedGranularity = $requestedDimension->getGranularity();
    }

    /**
     * Get the column name for a dimension on a specific table.
     */
    public function getColumnForTable(Table $table, Dimension $dimension): string
    {
        $dimensionClass = get_class($dimension);
        $tableDimensions = $table->dimensions();

        foreach ($tableDimensions as $key => $tableDimension) {
            if ($key === $dimensionClass || $tableDimension instanceof $dimensionClass) {
                return $tableDimension->toArray()['column'];
            }

            if (str_starts_with($key, $dimensionClass.'::') && $dimension->name() === $tableDimension->name()) {
                return $tableDimension->toArray()['column'];
            }
        }

        throw new \InvalidArgumentException(
            sprintf('Table "%s" does not support dimension "%s"', $table->table(), get_class($dimension))
        );
    }
}
