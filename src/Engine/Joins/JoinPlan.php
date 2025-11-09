<?php

namespace NickPotts\Slice\Engine\Joins;

/**
 * Describes a set of joins needed to connect multiple tables.
 *
 * This is the output of JoinResolver and the input for query executors.
 * It's database-agnostic - different executors (SQL, software, HTTP) can
 * consume this plan and generate their native join syntax.
 */
final class JoinPlan
{
    /**
     * @param  array<JoinSpecification>  $joins
     */
    public function __construct(
        private array $joins = [],
    ) {}

    /**
     * Add a join to the plan.
     */
    public function add(JoinSpecification $join): self
    {
        $this->joins[] = $join;

        return $this;
    }

    /**
     * Get all joins.
     *
     * @return array<JoinSpecification>
     */
    public function all(): array
    {
        return $this->joins;
    }

    /**
     * Check if plan has any joins.
     */
    public function isEmpty(): bool
    {
        return empty($this->joins);
    }

    /**
     * Get number of joins.
     */
    public function count(): int
    {
        return count($this->joins);
    }

    /**
     * Get all unique tables involved in joins.
     *
     * @return array<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->joins as $join) {
            $tables[$join->fromTable] = true;
            $tables[$join->toTable] = true;
        }

        return array_keys($tables);
    }
}
