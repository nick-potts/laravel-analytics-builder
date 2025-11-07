<?php

namespace Workbench\App\Analytics\Orders;

use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Analytics\Customers\CustomersTable;
use Workbench\App\Analytics\Dimensions\CountryDimension;
use Workbench\App\Analytics\OrderItems\OrderItemsTable;

class OrdersTable extends Table
{
    protected string $table = 'orders';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('created_at'),
            Dimension::class.'::status' => Dimension::make('status')
                ->label('Order Status'),
            CountryDimension::class => CountryDimension::make('country'),
        ];
    }

    public function relations(): array
    {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id'),
        ];
    }
}
