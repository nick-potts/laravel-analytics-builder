# Slice Plugin System

Inspired by Filament's extensible architecture, Slice provides a comprehensive plugin system with logical extension points for customizing every aspect of the analytics query builder.

## Table of Contents
- [Overview](#overview)
- [Extension Point Catalog](#extension-point-catalog)
- [Creating Plugins](#creating-plugins)
- [Plugin Patterns](#plugin-patterns)
- [Complete Examples](#complete-examples)

---

## Overview

### Philosophy

Slice follows Filament's design principles:

1. **Convention over Configuration** - Sensible defaults with explicit overrides
2. **Composable Components** - Mix and match different extensions
3. **Type-Safe Interfaces** - Contracts ensure compatibility
4. **Auto-Discovery** - Automatic registration where possible
5. **Fluent Builders** - Chainable, expressive APIs

### Plugin Architecture

```
┌─────────────────────────────────────────────────────┐
│              Core Extension Points                   │
├─────────────────────────────────────────────────────┤
│  Drivers  │  Metrics  │  Dimensions  │  Plans       │
│  Grammars │  Formatters │  Tables  │  Resolvers    │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│              Plugin Registration                     │
├─────────────────────────────────────────────────────┤
│  ServiceProvider  │  Registry  │  Macros  │  Config │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│                 Runtime Usage                        │
├─────────────────────────────────────────────────────┤
│        Slice::query()->metrics()->get()             │
└─────────────────────────────────────────────────────┘
```

---

## Extension Point Catalog

### 1. Drivers (Database Connectors)

**Similar to:** Filament's Database Drivers, Column Types

**Purpose:** Add support for custom databases (ClickHouse, TimescaleDB, DuckDB, etc.)

**Interfaces:**
- `QueryDriver` - Main driver interface
- `QueryAdapter` - Query builder wrapper
- `QueryGrammar` - SQL generation

**Files:**
- `src/Contracts/QueryDriver.php`
- `src/Contracts/QueryAdapter.php`
- `src/Engine/Grammar/QueryGrammar.php`

**Extension Pattern:**

```php
// 1. Create Grammar
class DuckDBGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        return match($granularity) {
            'hour' => "date_trunc('hour', {$table}.{$column})",
            'day' => "date_trunc('day', {$table}.{$column})",
            'week' => "date_trunc('week', {$table}.{$column})",
            'month' => "date_trunc('month', {$table}.{$column})",
            'year' => "date_trunc('year', {$table}.{$column})",
        };
    }
}

// 2. Create Driver
class DuckDBDriver implements QueryDriver
{
    public function name(): string
    {
        return 'duckdb';
    }

    public function createQuery(?string $table = null): QueryAdapter
    {
        // Return adapter wrapping DuckDB query builder
    }

    public function grammar(): QueryGrammar
    {
        return new DuckDBGrammar;
    }

    public function supportsDatabaseJoins(): bool
    {
        return true;
    }

    public function supportsCTEs(): bool
    {
        return true;
    }
}

// 3. Register
public function register(): void
{
    $this->app->singleton(QueryDriver::class, fn() => new DuckDBDriver);
}

// OR extend Laravel driver
public function boot(): void
{
    LaravelQueryDriver::extend('duckdb', DuckDBGrammar::class);
}
```

---

### 2. Metrics (Aggregations)

**Similar to:** Filament's Form Fields, Table Columns

**Purpose:** Add custom aggregation types (Median, Mode, Stddev, custom formulas)

**Interfaces:**
- `MetricContract` - Base metric contract
- `Metric` - Concrete metric
- `DatabaseMetric` - SQL aggregation

**Files:**
- `src/Contracts/MetricContract.php`
- `src/Contracts/Metric.php`
- `src/Contracts/DatabaseMetric.php`
- `src/Metrics/Aggregation.php`

**Extension Pattern:**

```php
class Median extends Aggregation
{
    protected float $percentile = 0.5;

    public function aggregationType(): string
    {
        return 'median';
    }

    public function applyToQuery(
        QueryAdapter $query,
        QueryDriver $driver,
        string $tableName,
        string $alias
    ): void {
        $column = $this->column;
        $driverName = $driver->name();

        $sql = match($driverName) {
            'pgsql' => "PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$tableName}.{$column})",
            'mysql' => $this->getMySqlMedian($tableName, $column),
            'clickhouse' => "quantile(0.5)({$tableName}.{$column})",
            default => throw new \RuntimeException("Median not supported for {$driverName}"),
        };

        $query->selectRaw("{$sql} as {$alias}");
    }

    protected function getMySqlMedian(string $table, string $column): string
    {
        // MySQL doesn't have native median, use subquery
        return "(SELECT AVG(mid.{$column}) FROM (
            SELECT {$column} FROM {$table}
            ORDER BY {$column}
            LIMIT 2 - (SELECT COUNT(*) FROM {$table}) % 2
            OFFSET (SELECT (COUNT(*) - 1) / 2 FROM {$table})
        ) as mid)";
    }
}

// Usage
Slice::query()
    ->metrics([
        Median::make('orders.total')->currency('USD')->label('Median Order Value'),
    ])
    ->get();
```

**Plugin Registry Pattern (like Percentile):**

```php
class CustomAggregation extends Aggregation
{
    protected static array $compilers = [];

    public static function compiler(string $driver, callable $callback): void
    {
        static::$compilers[$driver] = $callback;
    }

    public function applyToQuery(...): void
    {
        $driverName = $driver->name();

        if (isset(static::$compilers[$driverName])) {
            $sql = (static::$compilers[$driverName])($tableName, $this->column);
            $query->selectRaw("{$sql} as {$alias}");
            return;
        }

        // Default implementation
    }
}

// Third-party plugin
CustomAggregation::compiler('timescaledb', function($table, $column) {
    return "time_bucket('1 day', {$table}.{$column})";
});
```

---

### 3. Dimensions (Grouping & Filtering)

**Similar to:** Filament's Filters, Table Columns

**Purpose:** Add custom dimension types with specialized behavior

**Base Class:**
- `Dimension` - Base dimension with filters (only/except/where)
- `TimeDimension` - Time-based dimension with granularity

**Files:**
- `src/Schemas/Dimension.php`
- `src/Schemas/TimeDimension.php`

**Extension Pattern:**

```php
class UserAgeDimension extends Dimension
{
    protected array $brackets = [];

    public static function make(?string $column = 'birth_date'): static
    {
        return parent::make($column ?? 'birth_date')
            ->label('Age Group')
            ->type('string');
    }

    /**
     * Define age brackets: [0-17, 18-34, 35-54, 55+]
     */
    public function brackets(array $brackets): static
    {
        $this->brackets = $brackets;
        return $this;
    }

    /**
     * Custom SQL generation for age calculation
     */
    public function toSql(string $table, string $column, QueryDriver $driver): string
    {
        $driverName = $driver->name();

        $age = match($driverName) {
            'pgsql' => "EXTRACT(YEAR FROM AGE({$table}.{$column}))",
            'mysql' => "TIMESTAMPDIFF(YEAR, {$table}.{$column}, NOW())",
            'sqlite' => "CAST((julianday('now') - julianday({$table}.{$column})) / 365.25 AS INTEGER)",
        };

        // Build CASE statement for brackets
        if (!empty($this->brackets)) {
            $cases = [];
            foreach ($this->brackets as $label => $range) {
                $cases[] = "WHEN {$age} BETWEEN {$range[0]} AND {$range[1]} THEN '{$label}'";
            }
            return "CASE " . implode(" ", $cases) . " END";
        }

        return $age;
    }
}

// Usage
Slice::query()
    ->dimensions([
        UserAgeDimension::make('birth_date')
            ->brackets([
                'Young Adults' => [18, 34],
                'Middle Age' => [35, 54],
                'Seniors' => [55, 120],
            ])
            ->only(['Young Adults', 'Seniors']),
    ])
    ->get();
```

**Global Dimension Registration:**

```php
// In Table
public function dimensions(): array
{
    return [
        UserAgeDimension::class => UserAgeDimension::make('birth_date'),
        TimeDimension::class => TimeDimension::make('created_at'),
    ];
}
```

---

### 4. Formatters (Result Display)

**Similar to:** Filament's Display Columns, Form Field Formatting

**Purpose:** Custom formatting for metric results

**Files:**
- `src/Formatters/CurrencyFormatter.php`
- `src/Formatters/NumberFormatter.php`
- `src/Formatters/PercentageFormatter.php`
- `src/Formatters/DateFormatter.php`

**Extension Pattern:**

```php
class BytesFormatter
{
    protected int $decimals = 2;
    protected string $unit = 'auto'; // auto, B, KB, MB, GB, TB

    public static function make(): static
    {
        return new static;
    }

    public function decimals(int $decimals): static
    {
        $this->decimals = $decimals;
        return $this;
    }

    public function unit(string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function format(mixed $value): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $bytes = (float) $value;

        if ($this->unit === 'auto') {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $index = 0;

            while ($bytes >= 1024 && $index < count($units) - 1) {
                $bytes /= 1024;
                $index++;
            }

            return number_format($bytes, $this->decimals) . ' ' . $units[$index];
        }

        // Convert to specific unit
        $divisor = match($this->unit) {
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            default => 1,
        };

        return number_format($bytes / $divisor, $this->decimals) . ' ' . $this->unit;
    }
}

// Add to Aggregation via macro
Aggregation::macro('asBytes', function(?string $unit = 'auto') {
    return $this->meta(['formatter' => BytesFormatter::make()->unit($unit)]);
});

// Usage
Sum::make('files.size')->asBytes('MB');
```

---

### 5. Query Plans (Execution Strategies)

**Similar to:** Filament's Resource Pages, Custom Actions

**Purpose:** Add custom query execution strategies (e.g., for graph databases)

**Interface:**
- `QueryPlan`

**Files:**
- `src/Engine/Plans/QueryPlan.php`
- `src/Engine/Plans/DatabaseQueryPlan.php`
- `src/Engine/Plans/SoftwareJoinQueryPlan.php`

**Extension Pattern:**

```php
class GraphDatabasePlan implements QueryPlan
{
    public function __construct(
        protected string $cypherQuery,
        protected array $parameters = [],
    ) {}

    public function getDriver(): QueryDriver
    {
        return app(QueryDriver::class);
    }

    public function execute(): array
    {
        // Execute Cypher query against Neo4j
        $driver = $this->getDriver();

        if (!$driver instanceof GraphQueryDriver) {
            throw new \RuntimeException('GraphDatabasePlan requires GraphQueryDriver');
        }

        return $driver->executeCypher($this->cypherQuery, $this->parameters);
    }
}

// Extend QueryBuilder to use custom plan
class CustomQueryBuilder extends QueryBuilder
{
    protected function build(array $metrics, array $dimensions): QueryPlan
    {
        // Check if query requires graph traversal
        if ($this->requiresGraphQuery($metrics)) {
            return $this->buildGraphPlan($metrics, $dimensions);
        }

        return parent::build($metrics, $dimensions);
    }

    protected function buildGraphPlan(array $metrics, array $dimensions): GraphDatabasePlan
    {
        // Build Cypher query
        $cypher = "MATCH (o:Order)-[:PURCHASED_BY]->(c:Customer) RETURN ...";

        return new GraphDatabasePlan($cypher);
    }
}
```

---

### 6. Resolvers (Logic Components)

**Similar to:** Filament's Authorization, Custom Logic

**Purpose:** Extend dimension/join/dependency resolution logic

**Classes:**
- `DimensionResolver` - Maps dimensions to table columns
- `JoinResolver` - BFS join pathfinding
- `DependencyResolver` - DFS topological sort

**Files:**
- `src/Engine/DimensionResolver.php`
- `src/Engine/JoinResolver.php`
- `src/Engine/DependencyResolver.php`

**Extension Pattern:**

```php
class CustomJoinResolver extends JoinResolver
{
    /**
     * Override to support custom join types (e.g., fuzzy matching)
     */
    public function buildJoinGraph(array $tables): array
    {
        $graph = parent::buildJoinGraph($tables);

        // Add custom cross-domain joins
        foreach ($tables as $table) {
            $customJoins = $table->crossJoins();

            foreach ($customJoins as $join) {
                if ($join instanceof FuzzyTimeJoin) {
                    $graph[] = [
                        'from' => $table->table(),
                        'to' => $join->targetTable(),
                        'relation' => $join,
                    ];
                }
            }
        }

        return $graph;
    }

    /**
     * Override to apply custom join logic
     */
    public function applyJoins(QueryAdapter $query, array $joinPath): QueryAdapter
    {
        foreach ($joinPath as $join) {
            $relation = $join['relation'];

            if ($relation instanceof FuzzyTimeJoin) {
                // Custom fuzzy time join (e.g., ±1 hour window)
                $this->applyFuzzyTimeJoin($query, $join);
                continue;
            }

            // Default behavior
            $this->applyStandardJoin($query, $relation, $join['from'], $join['to']);
        }

        return $query;
    }

    protected function applyFuzzyTimeJoin(QueryAdapter $query, array $join): void
    {
        // Join within time window
        $query->join(
            $join['to'],
            fn($q) => $q
                ->on("{$join['from']}.timestamp", '>=', DB::raw("{$join['to']}.timestamp - INTERVAL 1 HOUR"))
                ->on("{$join['from']}.timestamp", '<=', DB::raw("{$join['to']}.timestamp + INTERVAL 1 HOUR"))
        );
    }
}

// Register in ServiceProvider
$this->app->singleton(JoinResolver::class, fn() => new CustomJoinResolver);
```

---

### 7. Tables (Schema Definitions)

**Similar to:** Filament's Resources, Models

**Purpose:** Define table schemas with dimensions and relations

**Base Class:**
- `Table`

**Relations:**
- `BelongsTo`
- `HasMany`
- `BelongsToMany`
- `CrossJoin` (custom)

**Files:**
- `src/Tables/Table.php`
- `src/Tables/Relation.php`
- `src/Tables/BelongsTo.php`, `HasMany.php`, etc.

**Extension Pattern:**

```php
class OrdersTable extends Table
{
    protected string $table = 'orders';

    /**
     * Define which dimensions this table supports
     */
    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('created_at')
                ->asTimestamp()
                ->minGranularity('hour'),

            CountryDimension::class => CountryDimension::make('country'),

            UserAgeDimension::class => UserAgeDimension::make('customer.birth_date')
                ->brackets([
                    'Young' => [18, 34],
                    'Mature' => [35, 54],
                    'Senior' => [55, 120],
                ]),

            // Anonymous dimensions
            Dimension::class.'::status' => Dimension::make('status')
                ->only(['completed', 'shipped']),
        ];
    }

    /**
     * Define foreign key relationships
     */
    public function relations(): array
    {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id'),
            'products' => $this->belongsToMany(
                ProductsTable::class,
                'order_items', // pivot table
                'order_id',
                'product_id'
            ),
        ];
    }

    /**
     * Define explicit cross-domain joins (non-FK)
     */
    public function crossJoins(): array
    {
        return [
            'ad_clicks' => FuzzyTimeJoin::make(
                AdClicksTable::class,
                'clicked_at',
                'created_at',
                window: '1 hour'
            ),
        ];
    }
}
```

**Custom Relation Type:**

```php
class FuzzyTimeJoin extends Relation
{
    public function __construct(
        protected string $relatedTable,
        protected string $relatedTimestamp,
        protected string $localTimestamp,
        protected string $window = '1 hour',
    ) {}

    public function targetTable(): string
    {
        return (new $this->relatedTable)->table();
    }

    public function window(): string
    {
        return $this->window;
    }
}
```

---

### 8. Macros (Dynamic Extensions)

**Similar to:** Laravel Macros, Filament Custom Methods

**Purpose:** Add methods to core classes at runtime

**Macroable Classes:**
- `Aggregation`
- `Computed`

**Files:**
- `src/Metrics/Aggregation.php` (uses `Macroable`)
- `src/Metrics/Computed.php` (uses `Macroable`)

**Extension Pattern:**

```php
// In ServiceProvider
use NickPotts\Slice\Metrics\Aggregation;
use NickPotts\Slice\Metrics\Computed;

public function boot(): void
{
    // Add custom formatting
    Aggregation::macro('asKilobytes', function() {
        return $this->label(fn($m) => $m->getLabel() . ' (KB)')
                    ->decimals(2)
                    ->meta(['unit' => 'KB']);
    });

    // Add custom operators
    Computed::macro('ratio', function(string $numerator, string $denominator) {
        return $this->expression("{$numerator} / NULLIF({$denominator}, 0)")
                    ->dependsOn($numerator, $denominator)
                    ->decimals(4);
    });

    // Add custom validation
    Aggregation::macro('validate', function() {
        if (!$this->column) {
            throw new \InvalidArgumentException('Column required');
        }
        return $this;
    });
}

// Usage
Sum::make('files.size')->asKilobytes();

Computed::ratio('orders.revenue', 'orders.cost')
    ->label('Profit Margin')
    ->forTable(new OrdersTable);
```

---

### 9. Registry (Dynamic Discovery)

**Similar to:** Filament's Plugin Registration

**Purpose:** Register custom metrics, tables, dimensions dynamically

**Class:**
- `Registry`

**Files:**
- `src/Support/Registry.php`
- `src/SliceServiceProvider.php`

**Extension Pattern:**

```php
// Auto-discovery (in ServiceProvider)
public function boot(): void
{
    $registry = $this->app->make(Registry::class);

    // Option 1: Manual registration
    $registry->registerMetricEnum(OrdersMetric::class);
    $registry->registerMetricEnum(AdSpendMetric::class);

    // Option 2: Auto-discover from directory
    $this->discoverMetricEnums('app/Analytics');

    // Option 3: Register from config
    $enums = config('slice.metric_enums', []);
    foreach ($enums as $enumClass) {
        $registry->registerMetricEnum($enumClass);
    }

    // Register custom tables
    $registry->registerTable(new CustomTable);

    // Register custom dimensions
    $registry->registerDimension('user_age', UserAgeDimension::class);
}

// String-based queries now work
Slice::query()
    ->metrics(['orders.revenue', 'ad_spend.cost']) // Registry lookup
    ->get();
```

**Plugin Package Pattern:**

```php
// In your plugin's ServiceProvider
namespace VendorName\SliceClickhouse;

use Illuminate\Support\ServiceProvider;
use NickPotts\Slice\Support\Registry;

class ClickhouseSliceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueryDriver::class, fn() => new ClickhouseDriver);
    }

    public function boot(): void
    {
        $registry = $this->app->make(Registry::class);

        // Register custom metrics
        $registry->registerMetricEnum(ClickhouseMetric::class);

        // Publish config
        $this->publishes([
            __DIR__.'/../config/slice-clickhouse.php' => config_path('slice-clickhouse.php'),
        ], 'slice-clickhouse-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'slice-clickhouse-migrations');
    }
}
```

---

## Plugin Patterns

### Pattern 1: Database Driver Plugin

**Example: `vendor/your-name/slice-timescaledb`**

**Structure:**
```
slice-timescaledb/
├── src/
│   ├── TimescaleDbDriver.php
│   ├── TimescaleDbGrammar.php
│   ├── TimescaleDbAdapter.php
│   └── TimescaleDbServiceProvider.php
├── config/
│   └── slice-timescaledb.php
├── tests/
├── composer.json
└── README.md
```

**Installation:**
```bash
composer require your-name/slice-timescaledb
```

**Usage:**
```php
// config/slice-timescaledb.php
return [
    'connection' => 'timescaledb',
    'enable_continuous_aggregates' => true,
];

// Automatically uses TimescaleDB driver
Slice::query()
    ->metrics([Sum::make('metrics.value')])
    ->dimensions([TimeDimension::make('time')->hourly()])
    ->get();
```

---

### Pattern 2: Aggregation Plugin

**Example: `vendor/your-name/slice-statistics`**

Provides: Median, Mode, Stddev, Variance, Correlation

**Structure:**
```
slice-statistics/
├── src/
│   ├── Metrics/
│   │   ├── Median.php
│   │   ├── Mode.php
│   │   ├── Stddev.php
│   │   └── Variance.php
│   └── StatisticsServiceProvider.php
├── tests/
├── composer.json
└── README.md
```

**Usage:**
```php
use YourName\SliceStatistics\Metrics\Median;
use YourName\SliceStatistics\Metrics\Stddev;

Slice::query()
    ->metrics([
        Median::make('orders.total')->label('Median Order'),
        Stddev::make('orders.total')->label('Order Stddev'),
    ])
    ->get();
```

---

### Pattern 3: Domain-Specific Plugin

**Example: `vendor/your-name/slice-ecommerce`**

Provides: Pre-built metrics for e-commerce (AOV, LTV, churn, cohorts)

**Structure:**
```
slice-ecommerce/
├── src/
│   ├── Tables/
│   │   ├── OrdersTable.php
│   │   ├── CustomersTable.php
│   │   └── ProductsTable.php
│   ├── Metrics/
│   │   ├── EcommerceMetric.php (enum)
│   │   └── Concerns/
│   │       └── HasCohortAnalysis.php
│   ├── Dimensions/
│   │   ├── CohortDimension.php
│   │   └── ProductCategoryDimension.php
│   └── EcommerceServiceProvider.php
├── database/
│   └── migrations/
├── config/
│   └── slice-ecommerce.php
└── README.md
```

**Usage:**
```php
use YourName\SliceEcommerce\Metrics\EcommerceMetric;
use YourName\SliceEcommerce\Dimensions\CohortDimension;

Slice::query()
    ->metrics([
        EcommerceMetric::AverageOrderValue,
        EcommerceMetric::CustomerLifetimeValue,
        EcommerceMetric::RepeatPurchaseRate,
    ])
    ->dimensions([
        CohortDimension::make('customers.created_at')->monthly(),
    ])
    ->get();
```

---

### Pattern 4: Integration Plugin

**Example: `vendor/your-name/slice-prometheus`**

Exports Slice metrics to Prometheus

**Structure:**
```
slice-prometheus/
├── src/
│   ├── PrometheusExporter.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── MetricsController.php
│   └── PrometheusServiceProvider.php
├── routes/
│   └── web.php
└── README.md
```

**Usage:**
```php
// config/slice-prometheus.php
return [
    'endpoint' => '/metrics',
    'metrics' => [
        'orders.revenue' => [
            'type' => 'gauge',
            'help' => 'Total revenue',
        ],
    ],
];

// Automatically exposes at /metrics
// GET /metrics
// # HELP orders_revenue Total revenue
// # TYPE orders_revenue gauge
// orders_revenue 150000.00
```

---

### Pattern 5: UI Plugin

**Example: `vendor/your-name/slice-charts`**

Provides chart generation from Slice results

**Structure:**
```
slice-charts/
├── src/
│   ├── Charts/
│   │   ├── LineChart.php
│   │   ├── BarChart.php
│   │   └── PieChart.php
│   ├── Concerns/
│   │   └── HasChartOptions.php
│   └── ChartsServiceProvider.php
├── resources/
│   └── views/
└── README.md
```

**Usage:**
```php
use YourName\SliceCharts\Charts\LineChart;

$results = Slice::query()
    ->metrics([OrdersMetric::Revenue])
    ->dimensions([TimeDimension::make('created_at')->daily()])
    ->get();

return LineChart::make($results)
    ->xAxis('orders_created_at_day')
    ->yAxis('orders_total')
    ->title('Revenue Over Time')
    ->render();
```

---

## Complete Examples

### Example 1: ClickHouse Driver Plugin

```php
// src/ClickhouseGrammar.php
namespace VendorName\SliceClickhouse;

use NickPotts\Slice\Engine\Grammar\QueryGrammar;

class ClickhouseGrammar extends QueryGrammar
{
    public function formatTimeBucket(string $table, string $column, string $granularity): string
    {
        return match($granularity) {
            'hour' => "toStartOfHour({$table}.{$column})",
            'day' => "toDate({$table}.{$column})",
            'week' => "toMonday({$table}.{$column})",
            'month' => "toStartOfMonth({$table}.{$column})",
            'year' => "toYear({$table}.{$column})",
        };
    }

    public function compileAggregation(string $type, string $column): string
    {
        // ClickHouse-specific aggregations
        return match($type) {
            'sum' => "sum({$column})",
            'count' => "count({$column})",
            'avg' => "avg({$column})",
            'median' => "quantile(0.5)({$column})",
            'percentile_95' => "quantile(0.95)({$column})",
            default => parent::compileAggregation($type, $column),
        };
    }
}

// src/ClickhouseDriver.php
namespace VendorName\SliceClickhouse;

use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Engine\Grammar\QueryGrammar;

class ClickhouseDriver implements QueryDriver
{
    public function __construct(
        protected ?ClickhouseConnection $connection = null
    ) {
        $this->connection ??= app('clickhouse');
    }

    public function name(): string
    {
        return 'clickhouse';
    }

    public function createQuery(?string $table = null): QueryAdapter
    {
        return new ClickhouseQueryAdapter(
            $this->connection->table($table),
            $this
        );
    }

    public function grammar(): QueryGrammar
    {
        return new ClickhouseGrammar;
    }

    public function supportsDatabaseJoins(): bool
    {
        return true;
    }

    public function supportsCTEs(): bool
    {
        return true;
    }
}

// src/ClickhouseServiceProvider.php
namespace VendorName\SliceClickhouse;

use Illuminate\Support\ServiceProvider;
use NickPotts\Slice\Contracts\QueryDriver;

class ClickhouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/slice-clickhouse.php', 'slice-clickhouse');

        // Register driver
        $this->app->singleton(QueryDriver::class, function () {
            return new ClickhouseDriver;
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/slice-clickhouse.php' => config_path('slice-clickhouse.php'),
        ], 'slice-clickhouse-config');
    }
}

// composer.json
{
    "name": "vendor-name/slice-clickhouse",
    "description": "ClickHouse driver for Slice analytics",
    "require": {
        "php": "^8.1",
        "nick-potts/slice": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "VendorName\\SliceClickhouse\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "VendorName\\SliceClickhouse\\ClickhouseServiceProvider"
            ]
        }
    }
}
```

---

### Example 2: Machine Learning Metrics Plugin

```php
// src/Metrics/MachineLearningMetric.php
namespace VendorName\SliceMl;

enum MachineLearningMetric: string implements MetricContract
{
    use EnumMetric;

    case MeanSquaredError = 'mse';
    case RootMeanSquaredError = 'rmse';
    case MeanAbsoluteError = 'mae';
    case R2Score = 'r2';

    public function table(): Table
    {
        return new PredictionsTable;
    }

    public function get(): Metric
    {
        return match($this) {
            self::MeanSquaredError => Computed::make('AVG(POW(actual - predicted, 2))')
                ->dependsOn('predictions.actual', 'predictions.predicted')
                ->label('Mean Squared Error')
                ->decimals(4)
                ->forTable($this->table()),

            self::RootMeanSquaredError => Computed::make('SQRT(AVG(POW(actual - predicted, 2)))')
                ->dependsOn('predictions.actual', 'predictions.predicted')
                ->label('Root Mean Squared Error')
                ->decimals(4)
                ->forTable($this->table()),

            self::MeanAbsoluteError => Computed::make('AVG(ABS(actual - predicted))')
                ->dependsOn('predictions.actual', 'predictions.predicted')
                ->label('Mean Absolute Error')
                ->decimals(4)
                ->forTable($this->table()),

            self::R2Score => Computed::make(
                '1 - (SUM(POW(actual - predicted, 2)) / SUM(POW(actual - avg_actual, 2)))'
            )
                ->dependsOn('predictions.actual', 'predictions.predicted', 'predictions.avg_actual')
                ->label('R² Score')
                ->decimals(4)
                ->forTable($this->table()),
        };
    }
}

// Usage
use VendorName\SliceMl\Metrics\MachineLearningMetric;

Slice::query()
    ->metrics([
        MachineLearningMetric::MeanSquaredError,
        MachineLearningMetric::R2Score,
    ])
    ->dimensions([
        TimeDimension::make('predictions.created_at')->daily(),
    ])
    ->get();
```

---

### Example 3: Custom Dimension with Special Filtering

```php
// src/Dimensions/GeoLocationDimension.php
namespace VendorName\SliceGeo;

use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Contracts\QueryDriver;

class GeoLocationDimension extends Dimension
{
    protected ?float $latitude = null;
    protected ?float $longitude = null;
    protected ?float $radiusKm = null;

    public static function make(?string $column = 'location'): static
    {
        return parent::make($column ?? 'location')
            ->label('Location')
            ->type('geography');
    }

    /**
     * Filter by radius around a point
     */
    public function withinRadius(float $lat, float $lng, float $radiusKm): static
    {
        $this->latitude = $lat;
        $this->longitude = $lng;
        $this->radiusKm = $radiusKm;
        return $this;
    }

    /**
     * Custom SQL generation for geography queries
     */
    public function toSql(string $table, string $column, QueryDriver $driver): string
    {
        $driverName = $driver->name();

        return match($driverName) {
            'pgsql' => "{$table}.{$column}::geography",
            'mysql' => "ST_AsText({$table}.{$column})",
            default => "{$table}.{$column}",
        };
    }

    /**
     * Apply radius filter
     */
    public function applyFilter(QueryAdapter $query, string $table, string $column, QueryDriver $driver): void
    {
        if ($this->latitude === null || $this->longitude === null || $this->radiusKm === null) {
            return;
        }

        $driverName = $driver->name();

        if ($driverName === 'pgsql') {
            // PostGIS
            $point = "ST_MakePoint({$this->longitude}, {$this->latitude})::geography";
            $query->whereRaw(
                "ST_DWithin({$table}.{$column}::geography, {$point}, ?)",
                [$this->radiusKm * 1000] // Convert to meters
            );
        } elseif ($driverName === 'mysql') {
            // MySQL spatial
            $point = "POINT({$this->longitude}, {$this->latitude})";
            $query->whereRaw(
                "ST_Distance_Sphere({$table}.{$column}, {$point}) <= ?",
                [$this->radiusKm * 1000]
            );
        }
    }
}

// Usage
use VendorName\SliceGeo\Dimensions\GeoLocationDimension;

Slice::query()
    ->metrics([OrdersMetric::Revenue])
    ->dimensions([
        GeoLocationDimension::make('stores.location')
            ->withinRadius(37.7749, -122.4194, 50), // 50km radius around SF
    ])
    ->get();
```

---

## Summary

### Extension Points Comparison with Filament

| Slice Extension Point | Filament Equivalent | Purpose |
|-----------------------|---------------------|---------|
| **Drivers** | Database Drivers | Add database support |
| **Metrics** | Form Fields, Table Columns | Custom aggregations |
| **Dimensions** | Filters, Table Columns | Grouping & filtering |
| **Formatters** | Display Columns | Result formatting |
| **Query Plans** | Resource Pages | Execution strategies |
| **Resolvers** | Authorization | Logic components |
| **Tables** | Resources, Models | Schema definitions |
| **Macros** | Custom Methods | Dynamic extensions |
| **Registry** | Plugin Registration | Auto-discovery |

### Quick Reference

**Creating a plugin package:**

1. Create Laravel package with ServiceProvider
2. Implement extension interfaces (Driver, Metric, Dimension, etc.)
3. Register in ServiceProvider's `boot()` or `register()`
4. Publish config/migrations if needed
5. Add auto-discovery in composer.json

**Registering extensions:**

```php
// Drivers
$this->app->singleton(QueryDriver::class, fn() => new CustomDriver);
LaravelQueryDriver::extend('custom', CustomGrammar::class);

// Metrics
$registry->registerMetricEnum(CustomMetric::class);

// Dimensions
// In Table: dimensions(): [CustomDimension::class => CustomDimension::make()]

// Macros
Aggregation::macro('customMethod', fn() => $this->meta(['key' => 'value']));

// Formatters
// Use meta() in metrics: ->meta(['formatter' => CustomFormatter::make()])
```

---

This plugin system provides Filament-level extensibility while maintaining type safety and clean architecture. Every component can be customized, replaced, or extended through well-defined interfaces.
