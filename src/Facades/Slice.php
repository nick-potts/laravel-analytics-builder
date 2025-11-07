<?php

namespace NickPotts\Slice\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \NickPotts\Slice\Slice query(?callable $callback = null)
 * @method static \NickPotts\Slice\Slice metrics(array $metrics)
 * @method static \NickPotts\Slice\Slice dimensions(array $dimensions)
 * @method static \NickPotts\Slice\Engine\ResultCollection get()
 */
class Slice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NickPotts\Slice\Slice::class;
    }
}
