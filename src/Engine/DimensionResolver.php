<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Schemas\Dimensions\Dimension;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * Resolves dimensions from table catalogs.
 *
 * Given a dimension request and a set of tables, finds which tables support
 * that dimension by querying their DimensionCatalog.
 */
final class DimensionResolver
{
    /**
     * Resolve a dimension across all tables that support it.
     *
     * Returns a mapping of table name => dimension instance from that table's catalog.
     *
     * @param  array<TableContract>  $tables
     * @return array<string, Dimension>  Keyed by table name
     */
    public function resolveDimension(
        Dimension $dimension,
        array $tables,
    ): array {
        $resolved = [];

        foreach ($tables as $table) {
            $catalog = $table->dimensions();

            // Try to find dimension in catalog
            foreach ($catalog->all() as $dimensionKey => $catalogDimension) {
                if ($this->matches($dimensionKey, $catalogDimension, $dimension)) {
                    $resolved[$table->name()] = $catalogDimension;
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * Check if a catalog dimension matches the requested dimension.
     *
     * Matches if:
     * - Dimension key is the class name of the requested dimension
     * - Catalog dimension is an instance of the requested dimension class
     */
    private function matches(
        string $catalogKey,
        Dimension $catalogDimension,
        Dimension $requestedDimension,
    ): bool {
        $requestedClass = $requestedDimension::class;

        // Direct class match
        if ($catalogKey === $requestedClass || $catalogDimension instanceof $requestedClass) {
            return true;
        }

        return false;
    }

    /**
     * Get the column name for a dimension on a specific table.
     *
     * Returns the column name from the resolved dimension instance.
     */
    public function getColumnForTable(Dimension $dimension): string
    {
        return $dimension->column();
    }

    /**
     * Validate time dimension granularity constraints.
     *
     * Currently a stub - should validate against table constraints in future.
     */
    public function validateGranularity(
        array $resolvedDimensions,
        Dimension $requestedDimension,
    ): void {
        if (! $requestedDimension instanceof TimeDimension) {
            return;
        }

        // TODO: Validate against table constraints like minGranularity()
    }
}
