<?php

namespace NickPotts\Slice\Tables;

class BelongsTo extends Relation
{
    public function __construct(
        protected string $table,
        protected string $foreignKey,
        protected string $ownerKey = 'id',
    ) {
        parent::__construct($table);
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function ownerKey(): string
    {
        return $this->ownerKey;
    }
}
