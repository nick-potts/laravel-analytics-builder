# Slice API Guide: Developer-Focused Usage Examples

**Version:** Phase 1-3 (Current implementation)
**Status:** Ready for production use
**Last Updated:** 2025-11-09

---

## Quick Start

### Zero-Config Analytics for Eloquent

```php
use NickPotts\Slice\Slice;
use NickPotts\Slice\Engine\Aggregations\Sum;
use NickPotts\Slice\Engine\Dimensions\TimeDimension;

// No Table classes needed! EloquentSchemaProvider auto-discovers your models
$result = Slice::query()
    ->metrics([
        Sum::make('orders.total')
    ])
    ->dimensions([
        TimeDimension::make('orders.created_at')->daily()
    ])
    ->where(['orders.status' => 'completed'])
    ->get();

// Output:
// [
//     { date: '2025-01-01', total: 50000 },
//     { date: '2025-01-02', total: 65000 },
//     ...
// ]
```

---

## Fundamental Concepts

### Three Core Building Blocks

#### 1. Metrics (Aggregations)

Quantitative measures: SUM, COUNT, AVG

```php
Sum::make('orders.total')          // Sum column
Count::make('customers.id')        // Count rows
Avg::make('orders.total')          // Average
```

**Anatomy:** `{aggregation}('{table}.{column}')`

#### 2. Dimensions (Grouping)

Properties to slice by: dates, categories, strings

```php
Dimension::make('customers.country')                           // Category
TimeDimension::make('orders.created_at')->daily()             // Time with granularity
TimeDimension::make('orders.created_at')->monthly()
TimeDimension::make('orders.created_at')->yearly()
```

#### 3. Filters (WHERE Clauses)

Constrain results

```php
->where(['orders.status' => 'completed'])
->where(['customers.country' => 'US'])
```

---

## Common Query Patterns

### Pattern 1: Single-Table Aggregation

**Scenario:** "Total revenue this month"

```php
use NickPotts\Slice\Slice;
use NickPotts\Slice\Engine\Aggregations\Sum;

$result = Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->where([
        'orders.created_at' => ['>=', '2025-01-01'],
        'orders.status' => 'completed'
    ])
    ->get();

// Output: { total: 125000 }
```

### Pattern 2: Time Series Aggregation

**Scenario:** "Daily revenue trend over past quarter"

```php
use NickPotts\Slice\Slice;
use NickPotts\Slice\Engine\Aggregations\Sum;
use NickPotts\Slice\Engine\Dimensions\TimeDimension;

$result = Slice::query()
    ->metrics([Sum::make('orders.total')->alias('revenue')])
    ->dimensions([
        TimeDimension::make('orders.created_at')->daily()
    ])
    ->where([
        'orders.created_at' => ['>=', now()->subQuarter()]
    ])
    ->get();

// Output:
// [
//     { date: '2025-08-09', revenue: 12500 },
//     { date: '2025-08-10', revenue: 15000 },
//     ...
// ]
```

### Pattern 3: Multi-Dimension Grouping

**Scenario:** "Revenue by country and month"

```php
$result = Slice::query()
    ->metrics([Sum::make('orders.total')->alias('revenue')])
    ->dimensions([
        Dimension::make('customers.country'),
        TimeDimension::make('orders.created_at')->monthly()
    ])
    ->get();

// Output:
// [
//     { country: 'US', month: '2025-01-01', revenue: 50000 },
//     { country: 'US', month: '2025-02-01', revenue: 65000 },
//     { country: 'UK', month: '2025-01-01', revenue: 30000 },
//     ...
// ]
```

### Pattern 4: Multi-Table Join

**Scenario:** "Revenue per customer"

```php
$result = Slice::query()
    ->metrics([
        Sum::make('orders.total')->alias('revenue'),
        Count::make('orders.id')->alias('order_count')
    ])
    ->dimensions([
        Dimension::make('customers.name')
    ])
    ->get();

// JoinResolver automatically finds: orders → customers
// Output:
// [
//     { name: 'ACME Corp', revenue: 100000, order_count: 25 },
//     { name: 'TechCorp', revenue: 75000, order_count: 18 },
//     ...
// ]
```

### Pattern 5: Complex Multi-Table Query

**Scenario:** "Revenue per account, with invoice metrics"

```php
$result = Slice::query()
    ->metrics([
        Sum::make('orders.total')->alias('order_revenue'),
        Sum::make('invoices.amount')->alias('invoice_revenue'),
        Avg::make('orders.total')->alias('avg_order_value')
    ])
    ->dimensions([
        Dimension::make('accounts.name'),
        TimeDimension::make('orders.created_at')->monthly()
    ])
    ->where([
        'invoices.status' => 'paid'
    ])
    ->get();

// JoinResolver finds: orders → customers → accounts, invoices → accounts
// Output:
// [
//     { account: 'ACME', month: '2025-01-01', order_revenue: 50000, invoice_revenue: 75000, avg_order_value: 5000 },
//     ...
// ]
```

---

## API Reference

### Slice Facade

```php
// Entry point
Slice::query(): QueryBuilder

// Example
$builder = Slice::query();
```

### QueryBuilder

```php
class QueryBuilder {
    // Configure metrics
    public function metrics(array $aggregations): self;

    // Configure dimensions
    public function dimensions(array $dimensions): self;

    // Add filters
    public function where(array $filters): self;

    // Execute query
    public function get(): mixed;

    // Get the query plan (debugging)
    public function plan(): QueryPlan;
}
```

### Aggregations

```php
// All aggregations support chaining
Sum::make('{table}.{column}')
    ->alias('name')          // Rename in result
    ->where(['amount' => ['>', 100]])  // (future)

Count::make('{table}.{column}')
    ->alias('count')
    ->distinct()             // COUNT(DISTINCT column) (future)

Avg::make('{table}.{column}')
    ->alias('average')
```

**Format:** `{table}.{column}`

- `table`: Eloquent model name or custom table name
- `column`: Column name in that table

### Dimensions

#### Regular Dimension

```php
Dimension::make('{table}.{column}')
    ->alias('name')          // Optional rename
```

#### Time Dimension

```php
TimeDimension::make('{table}.{column}')
    ->daily()                // GROUP BY DATE(column)
    ->weekly()               // GROUP BY WEEK(column)
    ->monthly()              // GROUP BY MONTH(column)
    ->quarterly()            // (future)
    ->yearly()               // GROUP BY YEAR(column)
    ->alias('period')        // Optional rename
```

### Filters (WHERE Clauses)

```php
// Simple equality
->where(['orders.status' => 'completed'])

// Multiple conditions (AND)
->where([
    'orders.status' => 'completed',
    'customers.country' => 'US'
])

// Operators (future)
->where([
    'orders.total' => ['>', 1000],
    'orders.created_at' => ['>=', '2025-01-01']
])

// Array/IN operator (future)
->where([
    'customers.country' => ['IN', ['US', 'CA', 'MX']]
])
```

---

## Working with Different Data Sources

### Eloquent Models (Default)

No setup needed! EloquentSchemaProvider auto-discovers your models.

```php
// app/Models/Order.php
class Order extends Model {
    public function customer() {
        return $this->belongsTo(Customer::class);
    }
}

// app/Models/Customer.php
class Customer extends Model {
    public function orders() {
        return $this->hasMany(Order::class);
    }
}

// Usage
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->dimensions([Dimension::make('customers.country')])
    ->get(); // Works automatically
```

### Manual Table Definitions (Backward Compatibility)

For non-Eloquent data sources or complex custom logic:

```php
// app/Slice/OrdersTable.php
class OrdersTable implements SliceSource {
    public function getIdentifier(): string {
        return 'orders';
    }

    public function getConnection(): string {
        return 'mysql';
    }

    // ... other required methods
}

// Register in config/slice.php
return [
    'manual_tables' => [
        OrdersTable::class,
    ],
];

// Usage (same as Eloquent)
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->get();
```

### Custom Providers (Future Phases)

For ClickHouse, APIs, or other data sources:

```php
// Will be documented in Phase 6 (Provider Author Guide)
class ClickHouseProvider implements SchemaProvider {
    // Implement introspection logic
}
```

---

## Advanced Patterns

### Aliasing Results

```php
$result = Slice::query()
    ->metrics([
        Sum::make('orders.total')->alias('revenue'),
        Count::make('orders.id')->alias('order_count'),
        Avg::make('orders.total')->alias('avg_order_value')
    ])
    ->get();

// Output keys use aliases, not default names
// [{ revenue: 100000, order_count: 50, avg_order_value: 2000 }]
```

### Debugging: Inspecting Query Plans

```php
$builder = Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->dimensions([Dimension::make('customers.country')]);

// Get plan without executing
$plan = $builder->plan();

echo $plan->sql;                  // The actual SQL
echo count($plan->joins->joins);  // Number of joins
print_r($plan->joins->joinedTables); // Which tables got joined
```

### Pagination (Future)

```php
// Not yet supported, planned for Phase 4+
$result = Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->paginate(50);
```

### Result Formatting (Future)

```php
// Not yet supported, planned for Phase 4+
$result = Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->format('json')  // json, csv, excel
    ->get();
```

---

## Configuration

### config/slice.php

```php
return [
    // Schema provider priorities (lower = higher priority)
    'providers' => [
        // ManualTableProvider automatically registered (priority: 100)
        // EloquentSchemaProvider automatically registered (priority: 200)
        // Custom providers can be added here
    ],

    // Enable debug mode (shows SQL, timing, etc.)
    'debug' => env('APP_DEBUG', false),

    // Cache schema metadata
    'cache' => [
        'ttl' => env('CACHE_TTL', 3600), // seconds
    ],

    // Connection constraints
    'enforce_single_connection' => true, // Prevent cross-connection queries
];
```

---

## Error Handling

### Common Errors & Solutions

#### Error: "Table 'orders' not found"

```
Cause: Eloquent model not found or table doesn't exist
Solution: Check app/Models/ for Order.php and its getTable() value
```

#### Error: "Cross-connection query detected"

```
Cause: Trying to join tables from different database connections
Solution: Either move tables to same connection or wait for Phase 6 (data blending)
```

#### Error: "No path found between 'orders' and 'invoices'"

```
Cause: No relation exists between these tables
Solution: Define relation in Eloquent model, or use custom View (Phase 5)
```

#### Error: "Ambiguous dimension 'created_at'"

```
Cause: Multiple tables have this column
Solution: Be explicit: 'orders.created_at' instead of just 'created_at'
```

---

## Testing with Slice

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use NickPotts\Slice\Slice;
use NickPotts\Slice\Engine\Aggregations\Sum;
use NickPotts\Slice\Engine\Dimensions\TimeDimension;

class OrderAnalyticsTest extends TestCase {
    public function test_daily_revenue() {
        $result = Slice::query()
            ->metrics([Sum::make('orders.total')])
            ->dimensions([TimeDimension::make('orders.created_at')->daily()])
            ->where(['orders.status' => 'completed'])
            ->get();

        $this->assertIsArray($result);
        $this->assertTrue(count($result) > 0);
        $this->assertArrayHasKey('total', $result[0]);
    }
}
```

### Feature Test with Database

```php
use Tests\TestCase;

class OrderAnalyticsDatabaseTest extends TestCase {
    public function test_revenue_calculation() {
        // Arrange: Create test data
        Order::factory()->count(10)->create(['total' => 1000, 'status' => 'completed']);

        // Act: Run analytics query
        $result = Slice::query()
            ->metrics([Sum::make('orders.total')])
            ->where(['orders.status' => 'completed'])
            ->get();

        // Assert: Verify results
        $this->assertEquals(['total' => 10000], $result);
    }
}
```

---

## Performance Tips

### 1. Use Caching in Production

```php
// EloquentSchemaProvider caches automatically
// For repeated queries, schema resolution is < 5ms
```

### 2. Add Indexes for Common Filters

```php
// In migrations
Schema::table('orders', function (Blueprint $table) {
    $table->index('status');
    $table->index('customer_id');
    $table->index('created_at');
});
```

### 3. Limit Time Ranges

```php
// Good: Queries recent data
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->where(['orders.created_at' => ['>=', now()->subMonths(3)]])
    ->get();

// Bad: Queries all data
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->get();
```

---

## What's Coming in Future Phases

### Phase 4

- Auto-aggregations with intelligent GROUP BY
- Calculated measures (`revenue / customer_count`)
- Complex edge case handling

### Phase 5

- Views layer (semantic facade)
- Multi-hop relation filters
- Ambiguity resolution

### Phase 6

- Pre-aggregations (cache pre-computed results)
- Custom provider ecosystem
- Complete documentation

---

## Getting Help

### Documentation

- **CURRENT_ARCHITECTURE.md** - How Slice works internally
- **ROADMAP.md** - What's coming next
- **SCHEMA_PROVIDER_REFACTOR.md** - Deep technical dive

### Community

- GitHub Issues: Report bugs or ask questions
- Discussions: Share ideas and use cases
- Provider development: Build custom providers

---

**Last Updated:** 2025-11-09
**Status:** ✅ Phase 1-3 documented and ready
**Next Update:** After Phase 4 completion
