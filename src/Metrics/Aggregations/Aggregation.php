<?php

namespace NickPotts\Slice\Metrics\Aggregations;

use Illuminate\Database\Query\Grammars\Grammar;
use NickPotts\Slice\Metrics\AggregationCompiler;

abstract class Aggregation
{
    protected string $reference;
    protected ?string $alias = null;

    public function __construct(string $reference)
    {
        $this->reference = $reference;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setAlias(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    public function getAlias(): string
    {
        if ($this->alias) {
            return $this->alias;
        }
        $parts = explode('.', $this->reference);
        return strtolower(class_basename($this)) . '_' . implode('_', $parts);
    }

    public function toSql(Grammar $grammar): string
    {
        return AggregationCompiler::compile($this, $grammar);
    }
}
