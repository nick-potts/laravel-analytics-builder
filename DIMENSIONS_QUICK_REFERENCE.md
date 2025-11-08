# Slice Dimensions: Quick Reference Guide

## File Organization

```
src/
├── Schemas/
│   ├── Dimension.php              # Base dimension class
│   └── TimeDimension.php          # Time-based dimension with granularity
├── Engine/
│   ├── DimensionResolver.php      # Maps dimensions to tables
│   ├── QueryBuilder.php           # Integrates dimensions into queries
│   └── Grammar/
│       ├── QueryGrammar.php       # Abstract base
│       ├── MySqlGrammar.php       # MySQL: DATE_FORMAT()
│       ├── PostgresGrammar.php    # Postgres: date_trunc()
│       ├── SqlServerGrammar.php   # SQL Server: DATEADD/DATEDIFF
│       └── SqliteGrammar.php      # SQLite: strftime()
├── Tables/
│   └── Table.php                  # Tables declare dimensions()
└── Drivers/
    └── LaravelQueryDriver.php     # Resolves grammar by database

workbench/app/Analytics/
├── Orders/OrdersTable.php         # Example: class-based + named
├── AdSpend/AdSpendTable.php       # Example: precision + constraints
└── Dimensions/CountryDimension.php # Example: reusable dimension
```

## Three Ways to Use Dimensions

### 1. Class-Based (Reusable Dimensions)
```php
// Define in table
TimeDimension::class => TimeDimension::make('created_at'),
CountryDimension::class => CountryDimension::make('country'),

// Query
->dimensions([
    TimeDimension::make('created_at')->daily(),
    CountryDimension::make(),
])
```

### 2. Named (Table-Specific Dimensions)
```php
// Define in table
Dimension::class.'::status' => Dimension::make('status'),
Dimension::class.'::channel' => Dimension::make('channel'),

// Query
->dimensions([
    Dimension::make('status')->except(['cancelled']),
    Dimension::make('channel')->only(['email', 'sms']),
])
```

### 3. Combined (Both Patterns)
```php
public function dimensions(): array {
    return [
        TimeDimension::class => TimeDimension::make('created_at'),
        Dimension::class.'::status' => Dimension::make('status'),
        CountryDimension::class => CountryDimension::make('country'),
    ];
}
```

## Dimension Filters: Three Types

| Filter | Code | SQL | Use Case |
|--------|------|-----|----------|
| **only()** | `.only(['US','CA'])` | `IN (...)` | Whitelist values |
| **except()** | `.except(['cancelled'])` | `NOT IN (...)` | Exclude values |
| **where()** | `.where('>','2024-01-01')` | Custom operator | Comparison ops |

```php
Dimension::make('country')->only(['US', 'CA', 'MX'])
// WHERE country IN ('US', 'CA', 'MX')

Dimension::make('status')->except(['cancelled', 'pending'])
// WHERE status NOT IN ('cancelled', 'pending')

Dimension::make('created_at')->where('>=', '2024-01-01')
// WHERE created_at >= '2024-01-01'
```

## Time Dimensions: Granularity & Precision

### Granularity (How to bucket time)
```php
TimeDimension::make('created_at')
    ->hourly()     // 2024-01-15 14:00:00
    ->daily()      // 2024-01-15 (default)
    ->weekly()     // ISO week number
    ->monthly()    // 2024-01-01
    ->yearly()     // 2024
```

### Precision (What data the column contains)
```php
TimeDimension::make('created_at')
    ->asTimestamp()  // Full timestamp (default)
    ->asDate()       // Date only, no time
```

### Database-Specific SQL Generated

| DB | hourly | daily | week | month | year |
|----|--------|-------|------|-------|------|
| MySQL | `DATE_FORMAT(..., '%Y-%m-%d %H:00:00')` | `DATE(...)` | `DATE_FORMAT(..., '%Y-%u')` | `DATE_FORMAT(..., '%Y-%m')` | `YEAR(...)` |
| PostgreSQL | `date_trunc('hour', ...)` | `date_trunc('day', ...)` | `date_trunc('week', ...)` | `date_trunc('month', ...)` | `date_trunc('year', ...)` |
| SQL Server | `DATEADD(hour, DATEDIFF(hour, 0, ...), 0)` | `CAST(... AS DATE)` | `DATEADD(week, DATEDIFF(week, 0, ...), 0)` | `DATEADD(month, DATEDIFF(month, 0, ...), 0)` | `DATEADD(year, DATEDIFF(year, 0, ...), 0)` |
| SQLite | `strftime('%Y-%m-%d %H:00:00', ...)` | `date(...)` | `date(..., 'weekday 0', '-6 days')` | `strftime('%Y-%m-01', ...)` | `strftime('%Y-01-01', ...)` |

## DimensionResolver: The Matching Engine

### Step 1: Resolution
```
DimensionResolver::resolveDimensionForTables($tables, $dimension)
    ↓
Returns: ['table_name' => ['table' => Table, 'dimension' => Dimension]]
```

### Step 2: Column Lookup
```
DimensionResolver::getColumnForTable($table, $dimension)
    ↓
Returns: 'created_at', 'status', 'country', etc.
```

### Step 3: Validation
```
DimensionResolver::validateGranularity($resolved, $dimension)
    ↓
Checks: Can't use hourly on date-only columns (stub - not fully implemented)
```

## QueryBuilder: Four Key Methods

### 1. addDimensionSelects()
Adds SELECT expressions with database-specific bucketing

```
Input:  TimeDimension::make('created_at')->daily()
Output: SELECT DATE(orders.created_at) AS orders_created_at_day
```

### 2. addGroupBy()
Adds GROUP BY clauses matching SELECT expressions

```
Input:  TimeDimension::make('created_at')->daily()
Output: GROUP BY DATE(orders.created_at)
```

### 3. addDimensionFilters()
Adds WHERE clauses from filters

```
Input:  Dimension::make('status')->except(['cancelled'])
Output: WHERE orders.status NOT IN ('cancelled')
```

### 4. addOrderBy()
Adds ORDER BY for consistent results

```
Input:  TimeDimension::make('created_at')->daily()
Output: ORDER BY DATE(orders.created_at)
```

## Alias Naming Convention

QueryBuilder creates unique aliases to prevent conflicts:

```
Regular Dimension:    {table}_{dimension_name}
                      orders_status
                      
Time Dimension:       {table}_{dimension_name}_{granularity}
                      orders_created_at_day
                      orders_created_at_month
```

## Complete Query Example

### Definition
```php
class OrdersTable extends Table {
    protected string $table = 'orders';
    
    public function dimensions(): array {
        return [
            TimeDimension::class => TimeDimension::make('created_at'),
            Dimension::class.'::status' => Dimension::make('status'),
            CountryDimension::class => CountryDimension::make('country'),
        ];
    }
}
```

### Query
```php
Slice::query()
    ->metrics([Sum::make('orders.total'), Count::make('orders.id')])
    ->dimensions([
        TimeDimension::make('created_at')->daily(),
        Dimension::make('status')->except(['cancelled']),
        CountryDimension::make()->only(['US', 'CA']),
    ])
    ->get();
```

### Generated SQL
```sql
SELECT 
    DATE(orders.created_at) AS orders_created_at_day,
    orders.status AS orders_status,
    orders.country AS orders_country,
    SUM(orders.total) AS orders_total,
    COUNT(orders.id) AS orders_id
FROM orders
WHERE 
    orders.status NOT IN ('cancelled')
    AND orders.country IN ('US', 'CA')
GROUP BY 
    DATE(orders.created_at), orders.status, orders.country
ORDER BY 
    DATE(orders.created_at), orders.status, orders.country
```

### Result
```json
[
    {
        "orders_created_at_day": "2024-01-15",
        "orders_status": "completed",
        "orders_country": "US",
        "orders_total": 15000.00,
        "orders_id": 125
    },
    ...
]
```

## Key Concepts

### Dimension Classes
- **Dimension** - Regular dimensions (categories, attributes)
- **TimeDimension** - Time-based with granularity bucketing

### Resolution Match Types
1. **Class-based** - Direct class match: `TimeDimension::class`
2. **Named** - Prefixed key + name match: `Dimension::class.'::status'`

### Query Integration
1. **SELECT** - Add bucketed/formatted dimension columns
2. **GROUP BY** - Group by same expressions as SELECT
3. **WHERE** - Apply filters (only/except/where)
4. **ORDER BY** - Sort by dimensions for consistency

### Multi-Database Support
- Automatic grammar resolution by `DB::connection()->getDriverName()`
- Custom grammars via `LaravelQueryDriver::extend()`
- Each grammar implements `formatTimeBucket()` for database-specific SQL

## Known Limitations

1. **validateGranularity()** is a stub - doesn't actually validate constraints
2. **minGranularity()** method referenced in AdSpendTable doesn't exist yet
3. **Cross-table dimensions** not yet supported (e.g., grouping Orders by AdSpend channel)

## Testing Dimensions

```php
test('query with dimension filters', function () {
    $results = Slice::query()
        ->metrics([Count::make('orders.id')])
        ->dimensions([Dimension::make('status')])
        ->get();
    
    // Results grouped by status dimension
    expect($results)->toHaveCount(3); // e.g., 3 different statuses
});
```

