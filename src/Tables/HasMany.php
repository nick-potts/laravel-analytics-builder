<?php

namespace NickPotts\Slice\Tables;

class HasMany extends Relation
{
    public function __construct(
        protected string $table,
        protected string $foreignKey,
        protected string $localKey = 'id',
    ) {
        parent::__construct($table);
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function localKey(): string
    {
        return $this->localKey;
    }
}
