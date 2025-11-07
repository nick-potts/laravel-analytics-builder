<?php

namespace NickPotts\Slice\Engine\Plans;

class SoftwareJoinRelation
{
    public function __construct(
        protected string $key,
        protected string $from,
        protected string $to,
        protected string $type,
        protected string $fromAlias,
        protected string $toAlias,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function fromAlias(): string
    {
        return $this->fromAlias;
    }

    public function toAlias(): string
    {
        return $this->toAlias;
    }
}
