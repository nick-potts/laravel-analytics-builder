# QueryAdapter and QueryGrammar: Architecture Analysis

## Current Status

**Important:** These classes are defined in planning documents but NOT YET implemented in the codebase. They represent the intended architecture for Phase 3 of the implementation (Query Engine Integration).

- **QueryAdapter**: Interface contract (planned, not yet implemented)
- **QueryGrammar**: Classes for database-specific SQL generation (planned, not yet implemented)
- **QueryDriver**: Interface to create adapters and grammars (planned, not yet implemented)

---

## 1. QueryAdapter Interface

### Purpose
Minimal abstraction layer over database query builders. It adapts any database query builder (Laravel's Query Builder, or custom implementations) to a common interface that the query engine can use uniformly.

### Intended Location
`src/Contracts/QueryAdapter.php` (Interface only, no implementation yet)

### Interface Signature

```php
interface QueryAdapter {
    // Selection
    public function selectRaw(string $expression): void;
    public function select(array $columns): void;

    // Filtering
    public function where(string $column, string $operator, mixed $value): void;
    public function whereIn(string $column, array $values): void;
    public function whereNotIn(string $column, array $values): void;

    // Grouping
    public function groupBy(string $column): void;
    public function groupByRaw(string $expression): void;

    // Ordering
    public function orderBy(string $column, string $direction = 'asc'): void;
    public function orderByRaw(string $expression): void;

    // Joins
    public function join(string $table, string $first, string $operator, string $second): void;

    // CTEs (Common Table Expressions)
    public function withExpression(string $name, QueryAdapter $query): void;
    public function from(string $table): void;

    // Execution & Introspection
    public function execute(): array;
    public function getDriverName(): string;
    public function supportsCTEs(): bool;
    public function getNative(): mixed;  // Access underlying builder
}
```

### Key Design Points

1. **Minimal Methods**: Only what's needed for building analytics queries
2. **Chainable Pattern**: Methods return void but builder typically uses fluent API internally
3. **Raw Expressions**: `selectRaw()`, `groupByRaw()`, `orderByRaw()` for database-specific SQL
4. **Driver Introspection**: `getDriverName()`, `supportsCTEs()` for conditional logic
5. **Native Access**: `getNative()` for escape hatch to underlying builder when needed

### How It's Used

```php
// In QueryBuilder::buildDatabasePlan()
$adapter = $driver->createQuery('orders');  // Returns QueryAdapter instance

// Add dimension selects
$adapter->selectRaw("DATE(orders.created_at) as orders_created_at_day");

// Add metric aggregates
$adapter->selectRaw("SUM(orders.total) as orders_total");

// Add grouping
$adapter->groupByRaw("DATE(orders.created_at)");

// Add filtering
$adapter->where('orders.status', '!=', 'cancelled');

// Execute
$rows = $adapter->execute();
```

---

## 2. QueryGrammar Classes

### Purpose
Database-specific SQL generation, particularly for time bucketing in dimensions. Each database has different functions for date/time operations, so QueryGrammar abstracts this.

### Intended Location
`src/Engine/Grammar/` directory (planned, not yet implemented)

### Base Class Structure

```php
abstract class QueryGrammar {
    /**
     * Generate database-specific time bucketing SQL
     * 
     * @param string $table Table name (e.g., 'orders')
     * @param string $column Column name (e.g., 'created_at')
     * @param string $granularity Time bucket ('hour', 'day', 'week', 'month', 'year')
     * @return string SQL expression ready for SELECT/GROUP BY
     */
    abstract public function formatTimeBucket(
        string $table,
        string $column,
        string $granularity
    ): string;
}
```

### Planned Implementations

#### MySQL Grammar
```php
class MySqlGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        $fullColumn = "{$table}.{$column}";
        
        return match ($granularity) {
            'hour'   => "DATE_FORMAT({$fullColumn}, '%Y-%m-%d %H:00:00')",
            'day'    => "DATE({$fullColumn})",
            'week'   => "DATE_FORMAT({$fullColumn}, '%Y-%u')",     // Year-week
            'month'  => "DATE_FORMAT({$fullColumn}, '%Y-%m')",
            'year'   => "YEAR({$fullColumn})",
            default  => throw new \InvalidArgumentException("Unknown granularity: {$granularity}")
        };
    }
}
```

#### PostgreSQL Grammar
```php
class PostgresGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        $fullColumn = "{$table}.{$column}";
        
        return match ($granularity) {
            'hour'   => "date_trunc('hour', {$fullColumn})",
            'day'    => "date_trunc('day', {$fullColumn})",
            'week'   => "date_trunc('week', {$fullColumn})",
            'month'  => "date_trunc('month', {$fullColumn})",
            'year'   => "date_trunc('year', {$fullColumn})",
            default  => throw new \InvalidArgumentException("Unknown granularity: {$granularity}")
        };
    }
}
```

#### SQLite Grammar
```php
class SqliteGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        $fullColumn = "{$table}.{$column}";
        
        return match ($granularity) {
            'hour'   => "strftime('%Y-%m-%d %H:00:00', {$fullColumn})",
            'day'    => "date({$fullColumn})",
            'week'   => "date({$fullColumn}, 'weekday 0', '-6 days')",
            'month'  => "strftime('%Y-%m-01', {$fullColumn})",
            'year'   => "strftime('%Y-01-01', {$fullColumn})",
            default  => throw new \InvalidArgumentException("Unknown granularity: {$granularity}")
        };
    }
}
```

#### SQL Server Grammar
```php
class SqlServerGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        $fullColumn = "{$table}.{$column}";
        
        return match ($granularity) {
            'hour'   => "DATEADD(hour, DATEDIFF(hour, 0, {$fullColumn}), 0)",
            'day'    => "CAST({$fullColumn} AS DATE)",
            'week'   => "DATEADD(week, DATEDIFF(week, 0, {$fullColumn}), 0)",
            'month'  => "DATEADD(month, DATEDIFF(month, 0, {$fullColumn}), 0)",
            'year'   => "DATEADD(year, DATEDIFF(year, 0, {$fullColumn}), 0)",
            default  => throw new \InvalidArgumentException("Unknown granularity: {$granularity}")
        };
    }
}
```

#### Other Supported Databases
- **MariaDB**: Inherits from MySqlGrammar (same syntax)
- **Firebird**: Uses EXTRACT patterns
- **SingleStore**: Inherits from MySqlGrammar

### Output Examples

For a query grouping orders by day:

```php
$driver = new LaravelQueryDriver('mysql');
$grammar = $driver->grammar();  // Returns MySqlGrammar

$expr = $grammar->formatTimeBucket('orders', 'created_at', 'day');
// Returns: "DATE(orders.created_at)"

// Used in query:
$adapter->selectRaw($expr . " as orders_created_at_day");
$adapter->groupByRaw($expr);
```

Generated SQL for different databases:
- **MySQL**: `SELECT DATE(orders.created_at) as orders_created_at_day GROUP BY DATE(orders.created_at)`
- **PostgreSQL**: `SELECT date_trunc('day', orders.created_at) as orders_created_at_day GROUP BY date_trunc('day', orders.created_at)`
- **SQLite**: `SELECT date(orders.created_at) as orders_created_at_day GROUP BY date(orders.created_at)`

---

## 3. QueryDriver Interface

### Purpose
Factory for creating QueryAdapter instances and providing database capabilities information.

### Intended Location
`src/Contracts/QueryDriver.php` (Interface) and `src/Engine/Drivers/LaravelQueryDriver.php` (Implementation)

### Interface Signature

```php
interface QueryDriver {
    /**
     * Get the driver name (e.g., 'mysql', 'pgsql', 'sqlite')
     */
    public function name(): string;

    /**
     * Create a new query adapter for the given table
     */
    public function createQuery(?string $table = null): QueryAdapter;

    /**
     * Get the grammar for database-specific SQL generation
     */
    public function grammar(): QueryGrammar;

    /**
     * Can this driver execute database joins?
     */
    public function supportsDatabaseJoins(): bool;

    /**
     * Can this driver use Common Table Expressions (CTEs)?
     */
    public function supportsCTEs(): bool;
}
```

### LaravelQueryDriver Implementation (Planned)

```php
class LaravelQueryDriver implements QueryDriver {
    protected ?QueryGrammar $grammar = null;
    protected string $connection;

    public function __construct(string $connection = 'default') {
        $this->connection = $connection;
    }

    public function name(): string {
        return DB::connection($this->connection)->getDriverName();
        // Returns: 'mysql', 'pgsql', 'sqlite', 'sqlsrv', etc.
    }

    public function createQuery(?string $table = null): QueryAdapter {
        $builder = DB::connection($this->connection)->query();
        if ($table) {
            $builder->from($table);
        }
        return new LaravelQueryAdapter($builder);
    }

    public function grammar(): QueryGrammar {
        if (!$this->grammar) {
            $this->grammar = $this->resolveGrammar();
        }
        return $this->grammar;
    }

    protected function resolveGrammar(): QueryGrammar {
        $driver = $this->name();
        
        // Check for custom registered grammars
        if (isset(self::$customGrammars[$driver])) {
            return new self::$customGrammars[$driver];
        }

        // Built-in grammars
        return match ($driver) {
            'mysql'      => new MySqlGrammar(),
            'mariadb'    => new MariaDbGrammar(),
            'pgsql'      => new PostgresGrammar(),
            'sqlite'     => new SqliteGrammar(),
            'sqlsrv'     => new SqlServerGrammar(),
            'firebird'   => new FirebirdGrammar(),
            'singlestore' => new SingleStoreGrammar(),
            default      => new MySqlGrammar(),  // Safe fallback
        };
    }

    public function supportsDatabaseJoins(): bool {
        // All supported drivers support joins
        return true;
    }

    public function supportsCTEs(): bool {
        return match ($this->name()) {
            'mysql'       => $this->checkMySQLVersion(),  // MySQL 8.0+
            'pgsql'       => true,
            'sqlsrv'      => true,
            'firebird'    => true,
            'sqlite'      => $this->checkSQLiteVersion(),  // SQLite 3.8+
            'mariadb'     => true,
            'singlestore' => true,
            default       => false,
        };
    }

    /**
     * Register a custom grammar for a database driver
     */
    public static function extend(string $driver, string $grammarClass): void {
        self::$customGrammars[$driver] = $grammarClass;
    }
}
```

---

## 4. How They Work Together in Query Building

### Data Flow During Query Execution

```
┌─────────────────────────────────────────────────────────┐
│ QueryBuilder::addDimensionSelects()                     │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ 1. Receive: QueryAdapter, TimeDimension, table          │
│                                                          │
│ 2. Check if TimeDimension:                              │
│    Get granularity (e.g., 'day')                        │
│                                                          │
│ 3. Call driver.grammar().formatTimeBucket(              │
│        table='orders',                                  │
│        column='created_at',                             │
│        granularity='day'                                │
│    )                                                     │
│    → Returns: "DATE(orders.created_at)"                │
│       (or database-specific equivalent)                 │
│                                                          │
│ 4. Build SELECT expression:                             │
│    selectRaw("DATE(orders.created_at) as created_at_day")│
│                                                          │
│ 5. Same expression used in:                             │
│    - SELECT clause                                      │
│    - GROUP BY clause                                    │
│    - ORDER BY clause                                    │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### Complete Example: Multi-Database Query

```php
// Scenario: Query orders by day, any database driver

// Setup
$driver = app(QueryDriver::class);      // Auto-injected, detects database
$adapter = $driver->createQuery('orders');

// Get grammar
$grammar = $driver->grammar();  // Correct grammar for the database

// Build time-bucketed query (database-agnostic)
$granularity = 'day';
$timeBucket = $grammar->formatTimeBucket('orders', 'created_at', $granularity);

// Add to query
$adapter->selectRaw("{$timeBucket} as date_bucket");
$adapter->selectRaw("SUM(total) as revenue");
$adapter->groupByRaw($timeBucket);

// Execute
$results = $adapter->execute();

// Results are identical regardless of database:
// [
//     {date_bucket: '2024-01-01', revenue: 1500},
//     {date_bucket: '2024-01-02', revenue: 2300},
// ]
```

---

## 5. Interaction Between QueryAdapter and QueryGrammar

### Key Principle: Separation of Concerns

- **QueryGrammar**: Generates SQL fragments (what to do)
- **QueryAdapter**: Builds and executes queries (how to do it)

### QueryGrammar Does NOT Know About QueryAdapter
- Grammar only produces strings
- Grammar has no side effects
- Grammar is pure and testable

### QueryAdapter Does NOT Know About Grammar Details
- Adapter only knows it needs SQL expressions
- Adapter trusts Grammar to produce correct SQL
- Adapter is database-agnostic

### QueryBuilder Orchestrates Both
```php
class QueryBuilder {
    public function addDimensionSelects(
        QueryAdapter $adapter,
        array $dimensions,
        QueryDriver $driver
    ): void {
        foreach ($dimensions as $dimension) {
            if ($dimension instanceof TimeDimension) {
                // Get SQL from grammar
                $expr = $driver->grammar()->formatTimeBucket(
                    'orders',
                    'created_at',
                    $dimension->getGranularity()
                );
                
                // Use in adapter
                $adapter->selectRaw($expr);
                $adapter->groupByRaw($expr);
            }
        }
    }
}
```

---

## 6. Extensibility Points

### Adding Support for a New Database

1. **Create Grammar class**:
```php
class VerticaGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        // Vertica-specific time bucketing
    }
}
```

2. **Register in driver** (if using LaravelQueryDriver):
```php
LaravelQueryDriver::extend('vertica', VerticaGrammar::class);
```

3. **Done!** QueryBuilder automatically uses it.

### Adding Support for Time Bucketing Strategies

Not implemented yet, but could add:
```php
abstract class QueryGrammar {
    abstract public function formatTimeBucket(...): string;
    
    // Could add in future:
    // abstract public function formatDateAdd(...): string;
    // abstract public function formatDateDiff(...): string;
    // abstract public function formatRound(...): string;
}
```

---

## 7. Testing Strategy

### Unit Tests for Grammar

```php
test('mysql grammar generates correct day bucket', function () {
    $grammar = new MySqlGrammar();
    $result = $grammar->formatTimeBucket('orders', 'created_at', 'day');
    
    expect($result)->toBe('DATE(orders.created_at)');
});

test('postgres grammar generates correct day bucket', function () {
    $grammar = new PostgresGrammar();
    $result = $grammar->formatTimeBucket('orders', 'created_at', 'day');
    
    expect($result)->toBe("date_trunc('day', orders.created_at)");
});
```

### Integration Tests for Adapter + Grammar

```php
test('adapter with mysql grammar produces correct sql', function () {
    $driver = new LaravelQueryDriver('mysql');
    $adapter = $driver->createQuery('orders');
    
    $expr = $driver->grammar()->formatTimeBucket('orders', 'created_at', 'day');
    $adapter->selectRaw($expr);
    
    $sql = $adapter->getNative()->toSql();
    expect($sql)->toContain('DATE(orders.created_at)');
});
```

---

## 8. Current State vs. Future Implementation

### What Exists Now
- ZERO implementation in source code
- Complete architectural planning in DIMENSIONS_ARCHITECTURE.md
- References in planning documents

### What Will Be Built in Phase 3
These classes need to be created as part of "Query Engine Integration":
- Location: `src/Engine/`, `src/Contracts/`
- Priority: High (needed for aggregations)
- Dependencies: Phase 1-2 (SchemaProvider infrastructure) must complete first

### For Aggregations
Aggregations will use QueryAdapter and QueryGrammar the same way dimensions do:

```php
class Sum extends Metric {
    public function applyToQuery(QueryAdapter $adapter, QueryDriver $driver): void {
        $expr = "SUM({$this->column()})";
        
        // For custom aggregation expressions that need grammar:
        if ($this->needs_special_handling()) {
            $expr = $driver->grammar()->formatCustomAggregation(...);
        }
        
        $adapter->selectRaw("{$expr} as {$this->alias()}");
    }
}
```

---

## 9. Summary

| Aspect | QueryAdapter | QueryGrammar | QueryDriver |
|--------|--------------|--------------|-------------|
| **Purpose** | Query building abstraction | Database-specific SQL generation | Driver factory & capabilities |
| **Status** | Planned, not implemented | Planned, not implemented | Planned, not implemented |
| **Location** | `src/Contracts/QueryAdapter.php` | `src/Engine/Grammar/*.php` | `src/Contracts/QueryDriver.php` |
| **Key Method** | `selectRaw()`, `execute()` | `formatTimeBucket()` | `createQuery()`, `grammar()` |
| **Database-Aware** | No (abstraction) | Yes (implementations per DB) | Yes (resolution logic) |
| **Used By** | QueryBuilder, QueryExecutor | QueryBuilder | Everywhere (injected) |
| **Extension** | Custom adapters | Custom grammars | Register in container |

---

## 10. Key Insights for Aggregation Design

### How Aggregations Will Use This

1. **Aggregation receives QueryAdapter during build phase**
   ```php
   $metric->applyToQuery($adapter, $driver);
   ```

2. **For database-specific logic, use QueryGrammar**
   ```php
   $expr = $driver->grammar()->someMethod(...);
   $adapter->selectRaw($expr);
   ```

3. **For standard SQL, add directly to adapter**
   ```php
   $adapter->selectRaw("SUM(column) as alias");
   ```

### Safe Plugging Points

- **Metric.applyToQuery()**: Gets access to both adapter and driver
- **QueryGrammar**: Can extend with aggregation-specific methods
- **QueryAdapter**: Already has all needed methods (selectRaw, groupBy, etc.)

No breaking changes needed—aggregations fit into existing architecture seamlessly.

---

