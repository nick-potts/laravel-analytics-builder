<?php

namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\SliceSource;
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
     * @param  array<SliceSource>  $tables
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
     * - Dimension key is the class name AND names match
     * - Catalog dimension is same instance of requested dimension class AND names match
     *
     * For class-keyed dimensions, both instance check and name comparison ensure
     * we only match the exact dimension requested (e.g., 'status' != 'country').
     */
    private function matches(
        string $catalogKey,
        Dimension $catalogDimension,
        Dimension $requestedDimension,
    ): bool {
        $requestedClass = $requestedDimension::class;

        // Direct class match: key is the class name AND names match
        if ($catalogKey === $requestedClass) {
            return $catalogDimension->name() === $requestedDimension->name();
        }

        // Instance match: catalog dim is instance of requested class AND names match
        if ($catalogDimension instanceof $requestedClass) {
            return $catalogDimension->name() === $requestedDimension->name();
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
