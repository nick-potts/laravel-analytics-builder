<?php

namespace Workbench\App\Analytics\Orders;

use NickPotts\Slice\Contracts\EnumMetric;
use NickPotts\Slice\Contracts\Metric;
use NickPotts\Slice\Contracts\MetricContract;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Tables\Table;

enum OrdersMetric: string implements MetricContract
{
    use EnumMetric;
    case Revenue = 'revenue';
    case Fees = 'fees';
    case OrderCount = 'order_count';
    case ItemCost = 'item_cost';
    case Profit = 'profit';
    case ProfitMargin = 'profit_margin';
    case AverageOrderValue = 'average_order_value';

    public function table(): Table
    {
        return new OrdersTable;
    }

    public function get(): Metric
    {
        return match ($this) {
            self::Revenue => Sum::make('orders.total')
                ->label('Revenue')
                ->currency('USD'),

            self::Fees => Sum::make('orders.fees')
                ->label('Fees')
                ->currency('USD'),

            self::OrderCount => Count::make('orders.id')
                ->label('Order Count'),

            self::ItemCost => Sum::make('orders.item_cost')
                ->label('Item Cost')
                ->currency('USD'),

            self::Profit => Computed::make('revenue - item_cost')
                ->label('Profit')
                ->dependsOn('orders.revenue', 'orders.item_cost')
                ->currency('USD')
                ->forTable($this->table()),

            self::ProfitMargin => Computed::make('(revenue - item_cost) / revenue * 100')
                ->label('Profit Margin')
                ->dependsOn('orders.revenue', 'orders.item_cost')
                ->percentage()
                ->forTable($this->table()),

            self::AverageOrderValue => Computed::make('revenue / order_count')
                ->label('Average Order Value')
                ->dependsOn('orders.revenue', 'orders.order_count')
                ->currency('USD')
                ->forTable($this->table()),
        };
    }
}
