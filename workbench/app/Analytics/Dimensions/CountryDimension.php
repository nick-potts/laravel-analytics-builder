<?php

namespace Workbench\App\Analytics\Dimensions;

use NickPotts\Slice\Schemas\Dimension;

class CountryDimension extends Dimension
{
    public static function make(?string $column = 'country'): static
    {
        return parent::make($column ?? 'country')
            ->label('Country')
            ->type('string');
    }
}
