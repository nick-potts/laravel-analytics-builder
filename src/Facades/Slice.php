<?php

namespace NickPotts\Slice\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \NickPotts\Slice\Slice
 */
class Slice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NickPotts\Slice\Slice::class;
    }
}
