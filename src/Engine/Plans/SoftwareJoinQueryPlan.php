<?php

namespace NickPotts\Slice\Engine\Plans;

class SoftwareJoinQueryPlan implements QueryPlan
{
    /**
     * @param  array<string, SoftwareJoinTablePlan>  $tablePlans
     * @param  array<int, SoftwareJoinRelation>  $relations
     * @param  array<int, string>  $dimensionOrder
     * @param  array<int, string>  $metricAliases
     * @param  array<string, array>  $dimensionFilters
     * @param  array<int, string>  $joinAliases
     */
    public function __construct(
        protected string $primaryTable,
        protected array $tablePlans,
        protected array $relations,
        protected array $dimensionOrder,
        protected array $metricAliases,
        protected array $dimensionFilters,
        protected array $joinAliases,
    ) {
    }

    public function primaryTable(): string
    {
        return $this->primaryTable;
    }

    /**
     * @return array<string, SoftwareJoinTablePlan>
     */
    public function tablePlans(): array
    {
        return $this->tablePlans;
    }

    public function tablePlan(string $table): ?SoftwareJoinTablePlan
    {
        return $this->tablePlans[$table] ?? null;
    }

    /**
     * @return array<int, SoftwareJoinRelation>
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * @return array<int, string>
     */
    public function dimensionOrder(): array
    {
        return $this->dimensionOrder;
    }

    /**
     * @return array<int, string>
     */
    public function metricAliases(): array
    {
        return $this->metricAliases;
    }

    /**
     * @return array<string, array>
     */
    public function dimensionFilters(): array
    {
        return $this->dimensionFilters;
    }

    /**
     * @return array<int, string>
     */
    public function joinAliases(): array
    {
        return $this->joinAliases;
    }
}
