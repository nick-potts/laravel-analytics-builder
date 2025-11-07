<?php

namespace NickPotts\Slice\Tables;

abstract class Relation
{
    public function __construct(
        protected string $table,
    ) {}

    /**
     * Get the related table class name.
     */
    public function table(): string
    {
        return $this->table;
    }
}
