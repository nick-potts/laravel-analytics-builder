<?php

namespace Workbench\App\Analytics\Products;

use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Tables\Table;

class ProductsTable extends Table
{
    protected string $table = 'products';

    public function dimensions(): array
    {
        return [
            Dimension::class.'::category' => Dimension::make('category')
                ->label('Product Category')
                ->type('string'),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
