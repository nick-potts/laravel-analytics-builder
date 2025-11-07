<?php

namespace Workbench\App\Analytics\OrderItems;

use NickPotts\Slice\Tables\Table;
use Workbench\App\Analytics\Orders\OrdersTable;
use Workbench\App\Analytics\Products\ProductsTable;

class OrderItemsTable extends Table
{
    protected string $table = 'order_items';

    public function dimensions(): array
    {
        return [];
    }

    public function relations(): array
    {
        return [
            'order' => $this->belongsTo(OrdersTable::class, 'order_id'),
            'product' => $this->belongsTo(ProductsTable::class, 'product_id'),
        ];
    }
}
