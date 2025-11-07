# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**Slice** is a Laravel package for building type-safe, Filament-inspired analytics queries. It uses a table-centric architecture where metric enums reference tables, tables define dimensions and relations, and the query engine automatically resolves joins and builds queries using Laravel's query builder.

## Core Architecture

### Table-Centric Design

The architecture centers around three key concepts:

1. **Tables** (`src/Tables/Table.php`) - Define database tables with:
   - `dimensions()` - Maps dimension classes to column names
   - `relations()` - Defines FK relationships (BelongsTo, HasMany, etc.)
   - `crossJoins()` - Explicit cross-domain joins without FK relationships

2. **Metric Enums** - Type-safe enums implementing `MetricContract`:
   - Must implement `table(): Table` - Returns the table this metric belongs to
   - Must implement `get(): Metric` - Returns the metric definition (aggregation, column, formatting)
   - Example: `OrdersMetric::Revenue` returns `MoneyMetric::make('total')->currency('USD')`

3. **Dimensions** - Represent ways to slice data:
   - Global dimension classes (e.g., `CountryDimension`, `TimeDimension`)
   - Tables declare which dimensions they support via `dimensions()` array
   - Dimensions can have filters: `only()`, `except()`, `where()`
   - TimeDimensions have granularity: `hourly()`, `daily()`, `weekly()`, `monthly()`

### Query Flow

```
User Query
  → Slice::query()->metrics([OrdersMetric::Revenue])->dimensions([...])
  → normalizeMetrics() - Converts enums to {table, metric, key} format
  → QueryBuilder::build()
    → extractTablesFromMetrics() - Gets unique tables
    → JoinResolver::buildJoinGraph() - BFS to find join paths
    → addMetricSelects() - SELECT SUM/COUNT/AVG with aliases
    → addDimensionSelects() - SELECT dimensions (DATE_FORMAT for time)
    → addGroupBy() - GROUP BY dimension columns
    → addDimensionFilters() - WHERE clauses from dimension filters
  → QueryExecutor::run() - Executes Laravel query builder
  → PostProcessor::process() - Calculates computed metrics (stub)
  → Returns ResultCollection
```

### Key Engine Components

- **DimensionResolver** - Matches dimension instances to table columns, validates granularity constraints
- **JoinResolver** - Uses BFS to find shortest join paths between tables, applies joins via Laravel's `join()`
- **DependencyResolver** - Topological sort (DFS) for computed metrics that depend on other metrics
- **QueryBuilder** - 100% Laravel Query Builder (no raw SQL), uses `DB::table()`, `selectRaw()`, `join()`, `groupBy()`

### Registry & Auto-Discovery

`SliceServiceProvider` automatically discovers metric enums:
- Scans `app/Analytics/**/*Metric.php` recursively
- Registers enums via `Registry::registerMetricEnum()`
- Registry extracts tables, metrics, and dimensions for runtime lookup
- Supports both enum-based queries and string-based queries (via registry lookup)

## Development Commands

### Testing
```bash
composer test              # Run all tests (Pest)
composer test-coverage     # Run tests with coverage report
vendor/bin/pest --filter TestName  # Run specific test
```

### Code Quality
```bash
composer lint              # Format code (Pint) + static analysis (PHPStan)
composer format            # Format code only (Laravel Pint)
composer analyse           # Static analysis only (PHPStan)
```

### Workbench (Development Environment)
```bash
composer build             # Build workbench (Testbench)
composer serve             # Start dev server with workbench
```

The workbench (`workbench/app/`) contains working examples:
- `Analytics/Orders/` - OrdersTable, OrdersMetric (7 metrics, 3 dimensions)
- `Analytics/AdSpend/` - AdSpendTable, AdSpendMetric (6 metrics, 2 dimensions)
- `Analytics/Dimensions/` - Global dimensions (CountryDimension)

## Creating New Components

### Direct Aggregations (No Enum Needed!)
```php
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Metrics\Count;
use NickPotts\Slice\Metrics\Avg;

Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD')->label('Revenue'),
        Count::make('orders.id')->label('Order Count'),
        Avg::make('orders.total')->currency('USD')->decimals(2),
    ])
    ->get();
```

### Metric Enum (Optional Shortcut)
```php
enum OrdersMetric: string implements MetricContract {
    case Revenue = 'revenue';

    public function table(): Table {
        return new OrdersTable();
    }

    public function get(): MetricContract {
        return match($this) {
            self::Revenue => Sum::make('orders.total')
                ->currency('USD')
                ->label('Revenue'),
        };
    }
}
```

### Table Definition
```php
class OrdersTable extends Table {
    protected string $table = 'orders';

    public function dimensions(): array {
        return [
            TimeDimension::class => TimeDimension::make('created_at')
                ->asTimestamp()
                ->minGranularity('hour'),
            CountryDimension::class => CountryDimension::make('country'),
        ];
    }

    public function relations(): array {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
        ];
    }
}
```

### Computed Metrics
Metrics that depend on other metrics:
```php
use NickPotts\Slice\Metrics\Computed;

enum OrdersMetric {
    case Profit;

    public function get(): MetricContract {
        return match($this) {
            self::Profit => Computed::make('revenue - item_cost')
                ->dependsOn('orders.revenue', 'orders.item_cost')
                ->currency('USD')
                ->forTable($this->table()),
        };
    }
}
```

Note: PostProcessor currently returns `null` for computed metrics - expression evaluation needs implementation.

### Custom Aggregations (Plugins!)
```php
class Median implements MetricContract {
    public static function make(string $column): static {
        // Parse table.column
    }

    public function table(): Table {
        // Lookup from registry
    }

    public function get(): MetricContract {
        return $this;
    }

    public function toArray(): array {
        return ['aggregation' => 'median', ...];
    }
}

// Extend QueryBuilder to handle custom SQL
class MedianQueryBuilder extends QueryBuilder {
    protected function addMetricSelects(...) {
        if ($aggregation === 'median') {
            // Your custom SQL here
        }
        parent::addMetricSelects(...);
    }
}
```

## Important Patterns

### Dimension Resolution
When a dimension is used in a query:
1. DimensionResolver checks which tables (from metrics) implement that dimension class
2. Validates granularity for TimeDimensions (e.g., can't query hourly if table only has dates)
3. Maps to actual column names per table
4. Applies to SELECT, GROUP BY, and WHERE clauses

### Join Resolution
JoinResolver uses BFS to find shortest path between tables:
1. Builds graph from table relations
2. Finds path from source → target table
3. Generates JOIN clauses based on relation types
4. Handles BelongsTo, HasMany, BelongsToMany

### Time Dimension Bucketing
TimeDimension automatically generates database-specific SQL for bucketing via Grammar classes:

**MySQL/MariaDB:**
- `hourly()` → `DATE_FORMAT(column, '%Y-%m-%d %H:00:00')`
- `daily()` → `DATE(column)`
- `weekly()` → `DATE_FORMAT(column, '%Y-%u')`
- `monthly()` → `DATE_FORMAT(column, '%Y-%m')`
- `yearly()` → `YEAR(column)`

**PostgreSQL:**
- `hourly()` → `date_trunc('hour', column)`
- `daily()` → `date_trunc('day', column)`
- `weekly()` → `date_trunc('week', column)`
- `monthly()` → `date_trunc('month', column)`
- `yearly()` → `date_trunc('year', column)`

**SQLite:**
- `hourly()` → `strftime('%Y-%m-%d %H:00:00', column)`
- `daily()` → `date(column)`
- `weekly()` → `date(column, 'weekday 0', '-6 days')`
- `monthly()` → `strftime('%Y-%m-01', column)`
- `yearly()` → `strftime('%Y-01-01', column)`

**SQL Server:**
- `hourly()` → `DATEADD(hour, DATEDIFF(hour, 0, column), 0)`
- `daily()` → `CAST(column AS DATE)`
- `weekly()` → `DATEADD(week, DATEDIFF(week, 0, column), 0)`
- `monthly()` → `DATEADD(month, DATEDIFF(month, 0, column), 0)`
- `yearly()` → `DATEADD(year, DATEDIFF(year, 0, column), 0)`

### Multi-Database Support & Driver Architecture

Slice supports 7 database drivers out of the box:
- **MySQL** - `MySqlGrammar`
- **MariaDB** - `MariaDbGrammar` (extends MySQL)
- **PostgreSQL** - `PostgresGrammar`
- **SQL Server** - `SqlServerGrammar`
- **SQLite** - `SqliteGrammar`
- **SingleStore** - `SingleStoreGrammar` (extends MySQL)
- **Firebird** - `FirebirdGrammar`

**Architecture:**
```
QueryDriver (interface)
  └─ LaravelQueryDriver (implementation)
      ├─ resolveGrammar() - Auto-detects database driver
      ├─ createQuery() - Returns QueryAdapter
      └─ grammar() - Returns database-specific QueryGrammar

QueryGrammar (abstract)
  ├─ MySqlGrammar
  ├─ PostgresGrammar
  ├─ SqliteGrammar
  ├─ SqlServerGrammar
  ├─ MariaDbGrammar (extends MySqlGrammar)
  ├─ SingleStoreGrammar (extends MySqlGrammar)
  └─ FirebirdGrammar

QueryAdapter (interface)
  └─ LaravelQueryAdapter (wraps Illuminate\Database\Query\Builder)
```

### Plugin System for Custom Drivers

Third parties can add support for custom databases (Clickhouse, TimescaleDB, DuckDB, etc.):

```php
// Create custom grammar
class ClickhouseGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        return match($granularity) {
            'hour' => "toStartOfHour({$table}.{$column})",
            'day' => "toDate({$table}.{$column})",
            'week' => "toMonday({$table}.{$column})",
            'month' => "toStartOfMonth({$table}.{$column})",
            'year' => "toYear({$table}.{$column})",
        };
    }
}

// Register in service provider
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;

public function boot(): void {
    LaravelQueryDriver::extend('clickhouse', ClickhouseGrammar::class);
}
```

See `docs/custom-drivers.md` for complete guide on creating custom database drivers.

## File Organization

```
src/
├── Slice.php                      # Main query interface
├── SliceServiceProvider.php       # Auto-discovery & DI registration
├── Contracts/MetricContract.php   # Metric interface
├── Metrics/                       # Aggregation classes
│   ├── Sum.php                    # SUM aggregation
│   ├── Count.php                  # COUNT aggregation
│   ├── Avg.php                    # AVG aggregation
│   ├── Min.php                    # MIN aggregation
│   ├── Max.php                    # MAX aggregation
│   └── Computed.php               # Calculated metrics
├── Tables/                        # Table & relation classes
├── Schemas/                       # Dimension definitions
│   ├── Dimension.php              # Base dimension with filters
│   └── TimeDimension.php          # Time dimension with granularity
├── Engine/                        # Query building & execution
│   ├── QueryBuilder.php           # Laravel query builder integration
│   ├── QueryExecutor.php          # Execute queries
│   ├── DimensionResolver.php      # Dimension → table mapping
│   ├── JoinResolver.php           # BFS join path finding
│   ├── DependencyResolver.php     # DFS metric dependency sort
│   └── PostProcessor.php          # Compute calculated metrics
├── Support/
│   ├── Registry.php               # Metric/table/dimension registry
│   └── DatabaseInspector.php      # DB introspection (stub)
├── Formatters/                    # Currency, Number, Percentage, Date
└── Commands/                      # Generation commands (stubs)
```

## Common Patterns

### Querying
```php
// Direct aggregations (no enum needed!)
Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD'),
        Count::make('orders.id'),
        Avg::make('orders.items_count')->decimals(1),
    ])
    ->dimensions([TimeDimension::make('created_at')->daily()])
    ->get();

// Mix enums and direct aggregations
Slice::query()
    ->metrics([
        OrdersMetric::Revenue,  // Enum shortcut
        Sum::make('orders.shipping_cost')->currency('USD'),  // Direct
    ])
    ->dimensions([CountryDimension::make()->only(['AU', 'US'])])
    ->get();

// String-based (for APIs)
Slice::query()
    ->metrics(['orders.revenue', 'ad_spend.spend'])
    ->dimensions([TimeDimension::make('date')->daily()])
    ->get();
```

### Testing Queries
Use the workbench database and seeders for testing:
```php
// workbench/database/seeders/DatabaseSeeder.php contains test data
$this->artisan('db:seed');
```

## Design Philosophy

- **Type-safety first** - Enums provide IDE autocomplete and compile-time checks
- **Zero custom SQL** - All queries built via Laravel Query Builder
- **Filament-inspired DX** - Fluent builders, convention over configuration
- **Table as source of truth** - Tables define structure, enums reference tables
- **Automatic join resolution** - No manual join specification needed
- **Dimension-based grouping** - Dimensions know how to group themselves (time bucketing, etc.)

## Known Limitations

1. **Computed metrics** - PostProcessor doesn't evaluate expressions yet (returns null)
2. **Cross-domain joins** - Attribution joins (Orders ↔ AdSpend on time) not implemented
3. **Generation commands** - All commands are stubs (MakeMetric, MakeTable, etc.)
4. **Type exports** - No TypeScript/JSON Schema generation yet

## Testing Strategy

Tests live in `tests/` using Pest:
- Unit tests for engine components (DimensionResolver, JoinResolver, etc.)
- Feature tests for complete query flows
- Use workbench models and database for integration tests
