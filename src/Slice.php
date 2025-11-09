<?php

namespace NickPotts\Slice;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \NickPotts\Slice\Engine\QueryBuilder query()
 * @method static array normalizeMetrics(array $aggregations)
 * @method static \NickPotts\Slice\Support\SchemaProviderManager getManager()
 */
class Slice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'slice';
    }
}
