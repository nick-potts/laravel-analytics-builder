<?php

namespace NickPotts\Slice\Providers\Eloquent\Introspectors;

use Illuminate\Database\Eloquent\Model;
use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;

/**
 * Introspects Eloquent model primary keys.
 */
class PrimaryKeyIntrospector
{
    /**
     * Extract primary key information from a model.
     */
    public function introspect(Model $model): PrimaryKeyDescriptor
    {
        $key = $model->getKeyName();

        // Handle composite keys
        if (is_array($key)) {
            return new PrimaryKeyDescriptor(
                columns: $key,
                autoIncrement: false,
            );
        }

        return new PrimaryKeyDescriptor(
            columns: [$key],
            autoIncrement: $model->getIncrementing(),
        );
    }
}
