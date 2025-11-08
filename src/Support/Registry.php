<?php

namespace NickPotts\Slice\Support;

use NickPotts\Slice\Contracts\MetricEnum;
use NickPotts\Slice\Tables\Table;

class Registry
{
    /** @var array<string, class-string<MetricEnum&\UnitEnum>> */
    protected array $metricEnums = [];

    /** @var array<string, Table> */
    protected array $tables = [];

    /** @var array<string, array> */
    protected array $metrics = [];

    /** @var array<string, array> */
    protected array $dimensions = [];

    /**
     * Register a metric enum class.
     *
     * @param  class-string<MetricEnum&\UnitEnum>  $enumClass
     */
    public function registerMetricEnum(string $enumClass): void
    {
        // Get the first case to extract table info
        /** @var array<MetricEnum> $cases */
        $cases = $enumClass::cases();
        if (empty($cases)) {
            return;
        }

        $firstCase = $cases[0];
        $table = $firstCase->table();
        $tableName = $table->table();

        // Register table if not already registered
        if (! isset($this->tables[$tableName])) {
            $this->tables[$tableName] = $table;
        }

        // Register enum class
        $this->metricEnums[$tableName] = $enumClass;

        // Register each metric
        foreach ($cases as $case) {
            /** @var MetricEnum&\UnitEnum $case */
            $metric = $case->get();
            $metricArray = $metric->toArray();
            $key = $tableName.'.'.$metricArray['name'];
            $this->metrics[$key] = array_merge($metricArray, [
                'enum_class' => $enumClass,
                'enum_case' => $case->name,
                'table' => $tableName,
            ]);
        }

        // Register table dimensions
        foreach ($table->dimensions() as $dimensionKey => $dimension) {
            $dimensionName = $dimension->name();
            $key = $tableName.'.'.$dimensionName;
            $this->dimensions[$key] = array_merge($dimension->toArray(), [
                'table' => $tableName,
                'dimension_class' => $dimensionKey,
            ]);
        }
    }

    /**
     * Register a table.
     */
    public function registerTable(Table $table): void
    {
        $tableName = $table->table();
        $this->tables[$tableName] = $table;

        // Register table dimensions
        foreach ($table->dimensions() as $dimensionKey => $dimension) {
            $dimensionName = $dimension->name();
            $key = $tableName.'.'.$dimensionName;
            $this->dimensions[$key] = array_merge($dimension->toArray(), [
                'table' => $tableName,
                'dimension_class' => $dimensionKey,
            ]);
        }
    }

    /**
     * Get all registered metric enum classes.
     *
     * @return array<string, class-string<MetricEnum&\UnitEnum>>
     */
    public function metricEnums(): array
    {
        return $this->metricEnums;
    }

    /**
     * Get all registered tables.
     *
     * @return array<string, Table>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * Get all registered metrics.
     *
     * @return array<string, array>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get all registered dimensions.
     *
     * @return array<string, array>
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Get a table by name.
     */
    public function getTable(string $tableName): ?Table
    {
        return $this->tables[$tableName] ?? null;
    }

    /**
     * Alias for getTable() - for consistency with aggregation classes.
     */
    public function getTableByName(string $tableName): ?Table
    {
        return $this->getTable($tableName);
    }

    /**
     * Get a metric by key.
     */
    public function getMetric(string $key): ?array
    {
        return $this->metrics[$key] ?? null;
    }

    /**
     * Lookup a metric enum case by string key.
     */
    public function lookupMetric(string $key): ?MetricEnum
    {
        $metricData = $this->getMetric($key);
        if (! $metricData) {
            return null;
        }

        $enumClass = $metricData['enum_class'];
        $caseName = $metricData['enum_case'];

        return $enumClass::$caseName ?? null;
    }
}
