<?php

namespace NickPotts\Slice\Engine\Plans;

use NickPotts\Slice\Contracts\QueryAdapter;

class DatabaseQueryPlan implements QueryPlan
{
    public function __construct(
        protected QueryAdapter $adapter
    ) {
    }

    public function adapter(): QueryAdapter
    {
        return $this->adapter;
    }
}
