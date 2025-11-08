<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors\Casts;

use Illuminate\Database\Eloquent\Casts\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Introspects Eloquent model casts to discover all columns and their types.
 *
 * Handles:
 * - getCasts() - All model casts
 * - Timestamps (created_at, updated_at)
 * - Soft deletes (deleted_at)
 * - Legacy $dates property
 *
 * Returns richer information about each cast (CastInfo) including whether
 * it's an enum or custom cast implementation.
 */
class CastIntrospector
{
    /**
     * Discover all casts in a model.
     *
     * Returns map of column => CastInfo with detailed cast information.
     *
     * @return array<string, CastInfo>
     */
    public function discoverCasts(Model $model): array
    {
        $casts = [];

        // Discover from getCasts()
        foreach ($model->getCasts() as $column => $castType) {
            if (is_string($castType)) {
                $casts[$column] = new CastInfo(
                    column: $column,
                    castType: $castType,
                    isEnum: enum_exists($castType),
                    isCustom: is_a($castType, CastsAttributes::class, true),
                );
            }
        }

        // Discover from timestamps
        foreach ($this->timestampColumns($model) as $column) {
            $casts[$column] = new CastInfo(
                column: $column,
                castType: 'timestamp',
                isEnum: false,
                isCustom: false,
            );
        }

        // Discover from legacy $dates property
        foreach ($this->dateMutators($model) as $column => $castType) {
            // Only add if not already discovered from getCasts()
            if (!isset($casts[$column])) {
                $casts[$column] = new CastInfo(
                    column: $column,
                    castType: $castType,
                    isEnum: false,
                    isCustom: false,
                );
            }
        }

        return $casts;
    }

    /**
     * Discover all temporal columns in a model.
     *
     * Backward-compatible method. Returns map of column => precision ('date' or 'timestamp').
     *
     * @return array<string, string>
     */
    public function discoverTemporalColumns(Model $model): array
    {
        $temporal = [];

        foreach ($this->discoverCasts($model) as $castInfo) {
            // Map cast types to precision
            $castType = $castInfo->castType;
            $baseCast = strtolower(strtok($castType, ':') ?: $castType);

            if ($baseCast === 'date' || $baseCast === 'immutable_date') {
                $temporal[$castInfo->column] = 'date';
            } elseif (in_array($baseCast, ['datetime', 'immutable_datetime', 'timestamp', 'immutable_timestamp'], true)) {
                $temporal[$castInfo->column] = 'timestamp';
            }
        }

        return $temporal;
    }

    /**
     * Get timestamp columns from model.
     *
     * Includes:
     * - created_at / updated_at (if usesTimestamps)
     * - deleted_at (if uses SoftDeletes)
     *
     * @return array<int, string>
     */
    private function timestampColumns(Model $model): array
    {
        $columns = [];

        // Standard timestamp columns
        if ($model->usesTimestamps()) {
            if ($created = $model->getCreatedAtColumn()) {
                $columns[] = $created;
            }

            if ($updated = $model->getUpdatedAtColumn()) {
                $columns[] = $updated;
            }
        }

        // Soft delete column
        if ($this->usesSoftDeletes($model)) {
            $columns[] = $model->getDeletedAtColumn();
        }

        return array_values(array_filter($columns));
    }

    /**
     * Get columns from legacy $dates property.
     *
     * Some older Eloquent models use $dates instead of $casts.
     *
     * @return array<string, string>
     */
    private function dateMutators(Model $model): array
    {
        if (!method_exists($model, 'getDates')) {
            return [];
        }

        $columns = [];

        foreach ($model->getDates() as $column) {
            if ($column === null) {
                continue;
            }

            // Default to timestamp if not already discovered with different precision
            $columns[$column] = $columns[$column] ?? 'timestamp';
        }

        return $columns;
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
