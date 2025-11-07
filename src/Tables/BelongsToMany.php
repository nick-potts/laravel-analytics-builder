<?php

namespace NickPotts\Slice\Tables;

class BelongsToMany extends Relation
{
    public function __construct(
        protected string $table,
        protected string $pivotTable,
        protected string $foreignKey,
        protected string $relatedKey,
    ) {
        parent::__construct($table);
    }

    public function pivotTable(): string
    {
        return $this->pivotTable;
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function relatedKey(): string
    {
        return $this->relatedKey;
    }
}
