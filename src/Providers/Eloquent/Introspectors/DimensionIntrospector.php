<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\Dimensions\TimeDimension;

/**
 * Introspects Eloquent model casts to discover dimensions.
 */
class DimensionIntrospector
{
    /**
     * Extract dimensions from casts, timestamp columns, and mutators.
     */
    public function introspect(Model $model): DimensionCatalog
    {
        $dimensions = [];

        foreach ($this->discoverTemporalColumns($model) as $column => $precision) {
            if ($this->isAppendedAttribute($model, $column)) {
                continue;
            }

            $dimensions[TimeDimension::class . '::' . $column] = TimeDimension::make($column)
                ->precision($precision);
        }

        return new DimensionCatalog($dimensions);
    }

    /**
     * @return array<string, string>  Map of column => precision
     */
    private function discoverTemporalColumns(Model $model): array
    {
        $columns = [];

        foreach ($model->getCasts() as $column => $castType) {
            $precision = is_string($castType) ? $this->precisionFromCast($castType) : null;

            if ($precision !== null) {
                $columns[$column] = $precision;
            }
        }

        foreach ($this->timestampColumns($model) as $column) {
            $columns[$column] = 'timestamp';
        }

        foreach ($this->dateMutators($model) as $column => $precision) {
            $columns[$column] = $precision;
        }

        return $columns;
    }

    /**
     * Determine precision for known cast names (datetime/date).
     */
    private function precisionFromCast(string $castType): ?string
    {
        $normalized = strtolower(strtok($castType, ':') ?: $castType);

        return match ($normalized) {
            'date', 'immutable_date' => 'date',
            'datetime', 'immutable_datetime', 'timestamp' => 'timestamp',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function timestampColumns(Model $model): array
    {
        $columns = [];

        if ($model->usesTimestamps()) {
            if ($created = $model->getCreatedAtColumn()) {
                $columns[] = $created;
            }

            if ($updated = $model->getUpdatedAtColumn()) {
                $columns[] = $updated;
            }
        }

        if ($this->usesSoftDeletes($model)) {
            $columns[] = $model->getDeletedAtColumn();
        }

        return array_values(array_filter($columns));
    }

    /**
     * Capture additional date mutators declared via $dates/$casts.
     *
     * @return array<string, string>
     */
    private function dateMutators(Model $model): array
    {
        if (! method_exists($model, 'getDates')) {
            return [];
        }

        $columns = [];

        foreach ($model->getDates() as $column) {
            if ($column === null) {
                continue;
            }

            $columns[$column] = $columns[$column] ?? 'timestamp';
        }

        return $columns;
    }

    private function isAppendedAttribute(Model $model, string $column): bool
    {
        return in_array($column, $model->getAppends(), true);
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
