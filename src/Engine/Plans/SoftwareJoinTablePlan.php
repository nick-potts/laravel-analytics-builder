<?php

namespace NickPotts\Slice\Engine\Plans;

use NickPotts\Slice\Contracts\QueryAdapter;

class SoftwareJoinTablePlan
{
    public function __construct(
        protected string $table,
        protected QueryAdapter $adapter,
        protected bool $primary = false,
    ) {}

    public function table(): string
    {
        return $this->table;
    }

    public function adapter(): QueryAdapter
    {
        return $this->adapter;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }
}
