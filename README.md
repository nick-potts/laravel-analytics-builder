# Slice

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nick-potts/slice.svg?style=flat-square)](https://packagist.org/packages/nick-potts/slice)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/nick-potts/slice/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/nick-potts/slice/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/nick-potts/slice/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/nick-potts/slice/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/nick-potts/slice.svg?style=flat-square)](https://packagist.org/packages/nick-potts/slice)

**Slice** is a powerful Laravel package for building type-safe, Filament-inspired analytics queries. Stop rebuilding metrics dashboards from scratch—Slice handles the hard parts with a beautiful, fluent API.

```php
use NickPotts\Slice\Slice;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Schemas\TimeDimension;

Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD')->label('Revenue'),
        Count::make('orders.id')->label('Order Count'),
    ])
    ->dimensions([
        TimeDimension::make('created_at')->daily(),
    ])
    ->get();
```

## Why Slice?

- **Type-Safe** - Leverage PHP 8.2+ enums for IDE autocomplete and compile-time checks
- **Zero Raw SQL** - All queries built using Laravel's Query Builder
- **Automatic Joins** - Define table relationships once, Slice handles the rest
- **Multi-Database** - MySQL, PostgreSQL, SQL Server, SQLite, MariaDB, SingleStore, Firebird, Oracle
- **Filament-Inspired DX** - Fluent builders, convention over configuration
- **No Configuration Required** - Direct aggregations work out of the box, no enum setup needed

## Installation

```bash
composer require nick-potts/slice
```

**Requirements:**
- PHP 8.2+
- Laravel 11.0+

## Quick Start

### Direct Aggregations (No Setup Required!)

The fastest way to get started—just specify your metrics:

```php
use NickPotts\Slice\Slice;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Metrics\Avg;
use NickPotts\Slice\Schemas\Dimension;

Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD'),
        Avg::make('orders.items_count')->decimals(1),
    ])
    ->dimensions([
        Dimension::make('country')->only(['AU', 'US']),
    ])
    ->get();
```

### Create Reusable Metrics with Enums

For metrics you'll use repeatedly, create an enum:

```php
use NickPotts\Slice\Contracts\MetricContract;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Tables\Table;

enum OrdersMetric: string implements MetricContract
{
    case Revenue = 'revenue';
    case ShippingCost = 'shipping_cost';

    public function table(): Table
    {
        return new OrdersTable();
    }

    public function get(): MetricContract
    {
        return match($this) {
            self::Revenue => Sum::make('orders.total')
                ->currency('USD')
                ->label('Revenue'),
            self::ShippingCost => Sum::make('orders.shipping_cost')
                ->currency('USD')
                ->label('Shipping Cost'),
        };
    }
}
```

Then query with type safety:

```php
Slice::query()
    ->metrics([
        OrdersMetric::Revenue,
        OrdersMetric::ShippingCost,
    ])
    ->dimensions([TimeDimension::make('created_at')->monthly()])
    ->get();
```

### Define Tables for Automatic Joins

Tables define your schema and relationships:

```php
use NickPotts\Slice\Tables\Table;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Schemas\Dimension;

class OrdersTable extends Table
{
    protected string $table = 'orders';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('created_at')
                ->asTimestamp()
                ->minGranularity('hour'),
            Dimension::make('country'),
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
```

Now Slice automatically resolves joins across tables:

```php
// Automatically joins orders → customers
Slice::query()
    ->metrics([
        OrdersMetric::Revenue,
        Sum::make('customers.lifetime_value')->currency('USD'),
    ])
    ->get();
```

## Key Features

### Time Dimensions with Automatic Bucketing

```php
use NickPotts\Slice\Schemas\TimeDimension;

// Hourly, daily, weekly, monthly, yearly
TimeDimension::make('created_at')->daily()
TimeDimension::make('created_at')->monthly()
TimeDimension::make('created_at')->hourly()
```

Slice generates database-specific SQL for optimal performance across MySQL, PostgreSQL, SQLite, SQL Server, and more.

### Dimension Filters

```php
use NickPotts\Slice\Schemas\Dimension;

// Include only specific values
Dimension::make('country')->only(['AU', 'US'])

// Exclude values
Dimension::make('status')->except(['cancelled'])

// Custom where clauses
Dimension::make('total')->where('>', 100)
```

### Computed Metrics

Define metrics that depend on other metrics:

```php
use NickPotts\Slice\Metrics\Computed;

enum OrdersMetric
{
    case Profit;

    public function get(): MetricContract
    {
        return match($this) {
            self::Profit => Computed::make('revenue - cost')
                ->dependsOn('orders.revenue', 'orders.cost')
                ->currency('USD'),
        };
    }
}
```

### Multi-Database Support

Slice works with 7+ databases out of the box:
- MySQL 8.0+
- PostgreSQL 9.4+
- SQL Server 2008+
- SQLite 3.8.3+
- MariaDB 10.2+
- SingleStore 8.1+
- Firebird
- Oracle 9.2+

Custom database drivers can be added via the plugin system.

## Available Metrics

- `Sum::make()` - SUM aggregation
- `Count::make()` - COUNT aggregation
- `Avg::make()` - AVG aggregation
- `Min::make()` - MIN aggregation
- `Max::make()` - MAX aggregation
- `Computed::make()` - Calculated from other metrics

All metrics support formatting via chainable methods:
- `->currency('USD')` - Currency formatting
- `->decimals(2)` - Decimal precision
- `->label('Revenue')` - Human-readable label

## Documentation

For complete documentation including advanced features, custom drivers, and API reference, visit:

**[docs.slicephp.com](https://docs.slicephp.com)** _(coming soon)_

## Testing

```bash
composer test              # Run all tests
composer test-coverage     # Run tests with coverage
composer lint              # Format + static analysis
```

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover a security vulnerability, please email github@nickpotts.com.au instead of using the issue tracker.

## Credits

- [Nick Potts](https://github.com/nick-potts)
- [All Contributors](../../contributors)

Inspired by [Filament](https://filamentphp.com)'s beautiful developer experience and [Laravel CTE](https://github.com/staudenmeir/laravel-cte)'s elegant query building patterns.

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
