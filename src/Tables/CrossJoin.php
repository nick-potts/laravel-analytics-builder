<?php

namespace NickPotts\Slice\Tables;

class CrossJoin extends Relation
{
    public function __construct(
        protected string $table,
        protected string $leftKey,
        protected string $rightKey,
        protected ?string $condition = null,
    ) {
        parent::__construct($table);
    }

    public function leftKey(): string
    {
        return $this->leftKey;
    }

    public function rightKey(): string
    {
        return $this->rightKey;
    }

    public function condition(): ?string
    {
        return $this->condition;
    }
}
