# Slice Dimension Architecture Analysis

## Overview

The Slice dimension system provides a flexible, type-safe way to slice analytics queries across multiple dimensions (time, categories, geography, etc.). Dimensions work in conjunction with tables and the query builder to enable filtering, grouping, and time-based aggregations.

## 1. Core Dimension Classes

### 1.1 Dimension Base Class

**Location:** `/home/user/laravel-analytics-builder/src/Schemas/Dimension.php`

The `Dimension` class is the foundation for all dimension types. It provides:

```php
class Dimension {
    protected string $name;           // Unique dimension identifier
    protected ?string $label = null;  // Display label
    protected ?string $column = null; // Database column name
    protected string $type = 'string'; // Data type
    protected array $meta = [];        // Metadata storage
    protected array $filters = [];     // Applied filters
}
```

**Key Methods:**
- `make(string $name)` - Static factory to create dimension instance
- `label(string $label)` - Set display label
- `column(string $column)` - Override the column name (defaults to `name`)
- `type(string $type)` - Set data type (string, integer, datetime, etc.)
- `meta(array $meta)` - Attach metadata
- `name()` - Get dimension name
- `filters()` - Retrieve all applied filters
- `toArray()` - Serialize dimension to array

**Filter Methods:**
Three filter types can be applied to dimensions:

1. **only(array $values)** - Whitelist specific values
   ```php
   Dimension::make('country')->only(['US', 'CA', 'MX'])
   // Generates: WHERE country IN ('US', 'CA', 'MX')
   ```

2. **except(array $values)** - Blacklist specific values
   ```php
   Dimension::make('status')->except(['cancelled', 'pending'])
   // Generates: WHERE status NOT IN ('cancelled', 'pending')
   ```

3. **where(string $operator, mixed $value)** - Custom condition
   ```php
   Dimension::make('created_at')->where('>', '2024-01-01')
   // Generates: WHERE created_at > '2024-01-01'
   ```

### 1.2 TimeDimension Class

**Location:** `/home/user/laravel-analytics-builder/src/Schemas/TimeDimension.php`

Extends `Dimension` for time-based bucketing and granularity:

```php
class TimeDimension extends Dimension {
    protected string $granularity = 'day';      // hour, day, week, month, year
    protected string $precision = 'timestamp';  // timestamp or date
}
```

**Key Methods:**

**Granularity Helpers:**
```php
->hourly()   // GROUP BY hour
->daily()    // GROUP BY day (default)
->weekly()   // GROUP BY week
->monthly()  // GROUP BY month
->yearly()   // GROUP BY year
->granularity('custom') // Manual granularity setting
```

**Precision Methods:**
```php
->asTimestamp() // Full timestamp precision (default)
->asDate()      // Date-only precision (no time component)
->precision()   // Get current precision
->getGranularity() // Get current granularity
```

**Example Usage:**
```php
TimeDimension::make('created_at')
    ->asTimestamp()  // Column has full timestamp data
    ->daily()        // Group by day

TimeDimension::make('date')
    ->asDate()       // Column has date-only data
    ->monthly()      // Group by month
```

## 2. DimensionResolver: Mapping to Tables

**Location:** `/home/user/laravel-analytics-builder/src/Engine/DimensionResolver.php`

The `DimensionResolver` connects requested dimensions to table implementations.

### 2.1 resolveDimensionForTables()

Maps a dimension instance to all tables that support it:

```php
public function resolveDimensionForTables(
    array $tables, 
    Dimension $dimension
): array {
    // Returns: [
    //   'orders' => [
    //     'table' => OrdersTable,
    //     'dimension' => OrdersTable's TimeDimension definition
    //   ]
    // ]
}
```

**Resolution Strategy:**

Two matching mechanisms:

1. **Class-based matching** - Compare dimension class directly
   ```php
   // In OrdersTable
   public function dimensions(): array {
       return [
           TimeDimension::class => TimeDimension::make('created_at'),
           CountryDimension::class => CountryDimension::make('country'),
       ];
   }
   
   // Query uses:
   $dimensions = [TimeDimension::make('created_at')->daily()]
   // Matches via class equality: TimeDimension::class === TimeDimension::class
   ```

2. **Named matching** - Match by dimension name with key prefix
   ```php
   // In OrdersTable
   public function dimensions(): array {
       return [
           Dimension::class.'::status' => Dimension::make('status')
               ->label('Order Status'),
       ];
   }
   
   // Query uses:
   $dimensions = [Dimension::make('status')]
   // Matches via: Dimension::class.'::status' key AND 'status' name equality
   ```

**Code Path:**
```php
foreach ($tables as $table) {
    $tableDimensions = $table->dimensions();
    
    foreach ($tableDimensions as $key => $tableDimension) {
        // Check 1: Direct class match
        if ($key === $dimensionClass || $tableDimension instanceof $dimensionClass) {
            $resolved[$table->table()] = ['table' => $table, 'dimension' => $tableDimension];
            break;
        }
        
        // Check 2: Named match with prefix
        if (str_starts_with($key, $dimensionClass.'::') && 
            $dimension->name() === $tableDimension->name()) {
            $resolved[$table->table()] = ['table' => $table, 'dimension' => $tableDimension];
            break;
        }
    }
}
```

### 2.2 validateGranularity()

Validates time dimension granularity constraints:

```php
public function validateGranularity(
    array $resolvedDimensions, 
    Dimension $requestedDimension
): void {
    if (!method_exists($requestedDimension, 'getGranularity')) {
        return; // Not a TimeDimension, skip validation
    }
    
    $requestedGranularity = $requestedDimension->getGranularity();
    // Validation logic would check constraints like:
    // - Can't use hourly granularity if table only stores dates
}
```

**Note:** This method is currently a stub. Full validation should check:
- Table's `minGranularity()` constraint (e.g., AdSpendTable requires at least daily)
- Whether column precision supports requested granularity

### 2.3 getColumnForTable()

Resolves the actual database column name for a dimension on a specific table:

```php
public function getColumnForTable(
    Table $table, 
    Dimension $dimension
): string {
    // Returns: 'created_at', 'country', 'status', etc.
}
```

**Example:**
```php
// OrdersTable declares:
dimensions() => [TimeDimension::class => TimeDimension::make('created_at')]

// Query requests: TimeDimension::make('created_at')

// getColumnForTable() returns: 'created_at'
// (The column name from OrdersTable's dimension definition)
```

## 3. Table-Level Dimension Declaration

**Location:** `/home/user/laravel-analytics-builder/src/Tables/Table.php`

Tables declare which dimensions they support:

```php
abstract class Table {
    /**
     * @return array<class-string<Dimension>, Dimension>
     */
    public function dimensions(): array {
        return [];
    }
}
```

### 3.1 Dimension Declaration Patterns

**Pattern 1: Class-based (Recommended for reusable dimensions)**
```php
class OrdersTable extends Table {
    public function dimensions(): array {
        return [
            TimeDimension::class => TimeDimension::make('created_at'),
            CountryDimension::class => CountryDimension::make('country'),
        ];
    }
}
```

**Pattern 2: Named (For table-specific dimensions)**
```php
class OrdersTable extends Table {
    public function dimensions(): array {
        return [
            Dimension::class.'::status' => Dimension::make('status')
                ->label('Order Status'),
            Dimension::class.'::payment_method' => Dimension::make('payment_method')
                ->label('Payment Method'),
        ];
    }
}
```

**Pattern 3: Extended with metadata**
```php
class AdSpendTable extends Table {
    public function dimensions(): array {
        return [
            TimeDimension::class => TimeDimension::make('date')
                ->asDate()           // Date-only column
                ->minGranularity('day'), // Can't query hourly
            Dimension::class.'::channel' => Dimension::make('channel')
                ->label('Marketing Channel')
                ->type('string'),
        ];
    }
}
```

### 3.2 Real Examples

**OrdersTable**
```php
public function dimensions(): array {
    return [
        TimeDimension::class => TimeDimension::make('created_at'),
        Dimension::class.'::status' => Dimension::make('status')->label('Order Status'),
        CountryDimension::class => CountryDimension::make('country'),
    ];
}
```

**AdSpendTable**
```php
public function dimensions(): array {
    return [
        TimeDimension::class => TimeDimension::make('date')->asDate()->minGranularity('day'),
        Dimension::class.'::channel' => Dimension::make('channel')->label('Marketing Channel'),
    ];
}
```

**CountryDimension (Reusable)**
```php
class CountryDimension extends Dimension {
    public static function make(?string $column = 'country'): static {
        return parent::make($column ?? 'country')
            ->label('Country')
            ->type('string');
    }
}
```

## 4. QueryBuilder Integration

**Location:** `/home/user/laravel-analytics-builder/src/Engine/QueryBuilder.php`

The QueryBuilder uses dimensions in three main query phases:

### 4.1 addDimensionSelects()

Adds dimension columns to SELECT clause with proper bucketing for time dimensions.

**Code Flow:**
```php
protected function addDimensionSelects(
    QueryAdapter $query,
    array $dimensions,
    array $tables,
    ?string $limitToTable = null
): void {
    foreach ($dimensions as $dimension) {
        // 1. Resolve which tables support this dimension
        $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);
        
        // 2. Validate time dimension granularity
        if ($dimension instanceof TimeDimension) {
            $this->dimensionResolver->validateGranularity($resolved, $dimension);
        }
        
        foreach ($resolved as $tableName => $resolvedData) {
            if ($limitToTable && $tableName !== $limitToTable) {
                continue; // Skip dimensions for other tables
            }
            
            // 3. Get the actual column name
            $column = $this->dimensionResolver->getColumnForTable(
                $resolvedData['table'],
                $dimension
            );
            
            // 4. Build SELECT expression
            if ($dimension instanceof TimeDimension) {
                // Time dimension: apply database-specific bucketing
                $granularity = $dimension->getGranularity();
                $selectExpr = $this->buildTimeDimensionSelect($tableName, $column, $granularity);
                $alias = "{$tableName}_{$dimension->name()}_{$granularity}";
            } else {
                // Regular dimension: simple column reference
                $selectExpr = "{$tableName}.{$column}";
                $alias = "{$tableName}_{$dimension->name()}";
            }
            
            // 5. Add to query
            $query->selectRaw("{$selectExpr} as {$alias}");
        }
    }
}
```

**Example SQL Generated:**

For Orders with daily time dimension and status dimension:
```sql
SELECT 
    DATE(orders.created_at) as orders_created_at_day,
    orders.status as orders_status,
    SUM(orders.total) as orders_total
FROM orders
GROUP BY DATE(orders.created_at), orders.status
```

### 4.2 addGroupBy()

Adds GROUP BY clauses for dimensions.

**Code Flow:**
```php
protected function addGroupBy(
    QueryAdapter $query,
    array $dimensions,
    array $tables,
    ?string $limitToTable = null
): void {
    foreach ($dimensions as $dimension) {
        $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);
        
        foreach ($resolved as $tableName => $resolvedData) {
            if ($limitToTable && $tableName !== $limitToTable) {
                continue;
            }
            
            $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);
            
            if ($dimension instanceof TimeDimension) {
                // TIME DIMENSION: GROUP BY with bucketing
                $granularity = $dimension->getGranularity();
                $groupExpr = $this->buildTimeDimensionSelect($tableName, $column, $granularity);
                $query->groupByRaw($groupExpr);
            } else {
                // REGULAR DIMENSION: GROUP BY column directly
                $query->groupBy("{$tableName}.{$column}");
            }
        }
    }
}
```

**Generated GROUP BY Examples:**

For TimeDimension with daily granularity:
```sql
GROUP BY DATE(orders.created_at)
```

For regular Dimension:
```sql
GROUP BY orders.status
```

### 4.3 addDimensionFilters()

Applies WHERE clauses from dimension filters (only, except, where).

**Code Flow:**
```php
protected function addDimensionFilters(
    QueryAdapter $query,
    array $dimensions,
    array $tables,
    ?string $limitToTable = null
): void {
    foreach ($dimensions as $dimension) {
        $filters = $dimension->filters();
        
        if (empty($filters)) {
            continue; // No filters to apply
        }
        
        $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);
        
        foreach ($resolved as $tableName => $resolvedData) {
            if ($limitToTable && $tableName !== $limitToTable) {
                continue;
            }
            
            $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);
            $fullColumn = "{$tableName}.{$column}";
            
            // ONLY filter: IN clause
            if (isset($filters['only'])) {
                $query->whereIn($fullColumn, $filters['only']);
            }
            
            // EXCEPT filter: NOT IN clause
            if (isset($filters['except'])) {
                $query->whereNotIn($fullColumn, $filters['except']);
            }
            
            // WHERE filter: Custom operator
            if (isset($filters['where'])) {
                $operator = $filters['where']['operator'];
                $value = $filters['where']['value'];
                $query->where($fullColumn, $operator, $value);
            }
        }
    }
}
```

**Generated WHERE Examples:**

For `.only(['US', 'CA'])`:
```sql
WHERE orders.country IN ('US', 'CA')
```

For `.except(['cancelled'])`:
```sql
WHERE orders.status NOT IN ('cancelled')
```

For `.where('>', '2024-01-01')`:
```sql
WHERE orders.created_at > '2024-01-01'
```

### 4.4 addOrderBy()

Adds ORDER BY clauses for consistent result ordering.

**Code Flow:**
```php
protected function addOrderBy(
    QueryAdapter $query,
    array $dimensions,
    array $tables,
    ?string $limitToTable = null
): void {
    foreach ($dimensions as $dimension) {
        $resolved = $this->dimensionResolver->resolveDimensionForTables($tables, $dimension);
        
        foreach ($resolved as $tableName => $resolvedData) {
            if ($limitToTable && $tableName !== $limitToTable) {
                continue;
            }
            
            $column = $this->dimensionResolver->getColumnForTable($resolvedData['table'], $dimension);
            
            if ($dimension instanceof TimeDimension) {
                // TIME DIMENSION: ORDER BY with bucketing
                $granularity = $dimension->getGranularity();
                $orderExpr = $this->buildTimeDimensionSelect($tableName, $column, $granularity);
                $query->orderByRaw($orderExpr);
            } else {
                // REGULAR DIMENSION: ORDER BY column directly
                $query->orderBy("{$tableName}.{$column}");
            }
        }
    }
}
```

## 5. Time Dimension Bucketing: Database Grammar Architecture

### 5.1 Grammar System Overview

**Location:** `/home/user/laravel-analytics-builder/src/Engine/Grammar/QueryGrammar.php`

Slice supports 7+ databases with database-specific SQL for time bucketing:

```php
abstract class QueryGrammar {
    abstract public function formatTimeBucket(
        string $table,
        string $column,
        string $granularity
    ): string;
}
```

### 5.2 Grammar Implementations

**MySQL / MariaDB**
```php
class MySqlGrammar extends QueryGrammar {
    public function formatTimeBucket(string $table, string $column, string $granularity): string {
        $fullColumn = "{$table}.{$column}";
        
        return match ($granularity) {
            'hour'   => "DATE_FORMAT({$fullColumn}, '%Y-%m-%d %H:00:00')",
            'day'    => "DATE({$fullColumn})",
            'week'   => "DATE_FORMAT({$fullColumn}, '%Y-%u')",
            'month'  => "DATE_FORMAT({$fullColumn}, '%Y-%m')",
            'year'   => "YEAR({$fullColumn})",
        };
    }
}

class MariaDbGrammar extends MySqlGrammar {} // Inherits MySQL
class SingleStoreGrammar extends MySqlGrammar {} // Inherits MySQL
```

**PostgreSQL**
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
        };
    }
}
```

**SQL Server**
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
        };
    }
}
```

**SQLite**
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
        };
    }
}
```

**Additional Databases:**
- `FirebirdGrammar` - Firebird SQL
- `ClickHouseGrammar` - ClickHouse (OLAP database)

### 5.3 Grammar Resolution

**Location:** `/home/user/laravel-analytics-builder/src/Engine/Drivers/LaravelQueryDriver.php`

Grammar is automatically resolved based on database connection:

```php
protected function resolveGrammar(): QueryGrammar {
    $driverName = DB::connection($this->connection)->getDriverName();
    
    // Check for custom grammar first
    if (isset(static::$customGrammars[$driverName])) {
        $grammarClass = static::$customGrammars[$driverName];
        return new $grammarClass;
    }
    
    // Built-in grammars
    return match ($driverName) {
        'mysql'      => new MySqlGrammar,
        'mariadb'    => new MariaDbGrammar,
        'pgsql'      => new PostgresGrammar,
        'sqlsrv'     => new SqlServerGrammar,
        'singlestore' => new SingleStoreGrammar,
        'firebird'   => new FirebirdGrammar,
        'sqlite'     => new SqliteGrammar,
        default      => new MySqlGrammar, // Default fallback
    };
}
```

**Custom Grammar Registration:**
```php
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;
use NickPotts\Slice\Engine\Grammar\ClickhouseGrammar;

public function boot(): void {
    LaravelQueryDriver::extend('clickhouse', ClickhouseGrammar::class);
}
```

### 5.4 Usage in QueryBuilder

When `buildTimeDimensionSelect()` is called:

```php
protected function buildTimeDimensionSelect(
    string $table,
    string $column,
    string $granularity
): string {
    return $this->driver->grammar()->formatTimeBucket($table, $column, $granularity);
}
```

The grammar is automatically selected based on current database connection.

## 6. Complete Example: Multi-Table Query with Dimensions

### 6.1 Setup

**Orders Table Definition:**
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
    
    public function relations(): array {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
        ];
    }
}
```

**Query:**
```php
$results = Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD')->label('Revenue'),
        Count::make('orders.id')->label('Order Count'),
    ])
    ->dimensions([
        TimeDimension::make('created_at')->daily(),
        Dimension::make('status')->except(['cancelled']),
        CountryDimension::make()->only(['US', 'CA', 'MX']),
    ])
    ->get();
```

### 6.2 Resolution Process

**Step 1: DimensionResolver.resolveDimensionForTables()**
```
TimeDimension('created_at') → OrdersTable[TimeDimension::class]
Dimension('status') → OrdersTable[Dimension::class.'::status']
CountryDimension → OrdersTable[CountryDimension::class]
```

**Step 2: addDimensionSelects()**
```sql
SELECT 
    DATE(orders.created_at) AS orders_created_at_day,
    orders.status AS orders_status,
    orders.country AS orders_country,
    SUM(orders.total) AS orders_total,
    COUNT(orders.id) AS orders_id
```

**Step 3: addGroupBy()**
```sql
GROUP BY 
    DATE(orders.created_at),
    orders.status,
    orders.country
```

**Step 4: addDimensionFilters()**
```sql
WHERE 
    orders.status NOT IN ('cancelled')
    AND orders.country IN ('US', 'CA', 'MX')
```

**Step 5: addOrderBy()**
```sql
ORDER BY 
    DATE(orders.created_at),
    orders.status,
    orders.country
```

### 6.3 Final Query

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
    AND orders.country IN ('US', 'CA', 'MX')
GROUP BY 
    DATE(orders.created_at),
    orders.status,
    orders.country
ORDER BY 
    DATE(orders.created_at),
    orders.status,
    orders.country
```

**Result:**
```json
[
    {
        "orders_created_at_day": "2024-01-01",
        "orders_status": "completed",
        "orders_country": "US",
        "orders_total": 15000.00,
        "orders_id": 125
    },
    {
        "orders_created_at_day": "2024-01-02",
        "orders_status": "processing",
        "orders_country": "CA",
        "orders_total": 8500.00,
        "orders_id": 42
    }
]
```

## 7. Key Design Patterns

### 7.1 Dimension Resolution Algorithm

1. **Input:** Dimension instance, array of tables from metrics
2. **Process:**
   - For each table, iterate its `dimensions()` array
   - Match by class equality OR instanceof check
   - Match by named pattern (`Dimension::class.'::name'`) with name validation
   - Return mapping of table names to resolved dimension definitions
3. **Output:** Array of tables that support the dimension

### 7.2 Time Dimension Processing

1. **Input:** TimeDimension with granularity (hour, day, week, month, year)
2. **Process:**
   - Resolve dimension to table columns
   - Get database grammar from driver
   - Call `grammar()->formatTimeBucket($table, $column, $granularity)`
   - Generate database-specific SQL expression
   - Use expression in SELECT, GROUP BY, and ORDER BY clauses
3. **Output:** Properly bucketed time-series data

### 7.3 Filter Application

Three independent filters can be applied:

```
┌─ only() ────────────┐
├─ except() ──────────┤─→ WHERE clause generation
└─ where() ──────────┘
```

Each generates its own WHERE condition, all combined with AND logic.

### 7.4 Multi-Table Dimension Handling

When dimensions are used across multiple tables:

1. Each table has its own dimension definition
2. Resolver matches the dimension to applicable tables
3. Dimension selects/filters/grouping applied per-table
4. Aliases include table name to avoid conflicts: `{tableName}_{dimensionName}`

## 8. Known Limitations & TODOs

### 8.1 Granularity Validation

The `validateGranularity()` method in DimensionResolver is currently a stub:

```php
public function validateGranularity(array $resolvedDimensions, Dimension $requestedDimension): void {
    if (!method_exists($requestedDimension, 'getGranularity')) {
        return;
    }
    
    $requestedGranularity = $requestedDimension->getGranularity();
    // TODO: Actually validate against minGranularity constraints
}
```

**Should validate:**
- Table's declared `minGranularity()` constraint
- Column precision (can't use hourly on date-only columns)

### 8.2 Missing minGranularity() Method

The `AdSpendTable` references `.minGranularity('day')` but this method doesn't exist in TimeDimension yet:

```php
TimeDimension::make('date')
    ->asDate()
    ->minGranularity('day') // ← Method doesn't exist
```

### 8.3 Cross-Table Dimension Joins

Current architecture handles dimensions per-table. Cross-table dimension joins (e.g., Orders grouped by AdSpend channel) would require:
- Dimension to table mapping resolution
- Join specification between tables
- Alias management to prevent conflicts

## 9. Architecture Summary

```
Dimension Request
    ↓
DimensionResolver
    ├─ resolveDimensionForTables() → Find supporting tables
    ├─ validateGranularity() → Check time constraints
    └─ getColumnForTable() → Map to column names
    ↓
QueryBuilder Methods
    ├─ addDimensionSelects()
    │   ├─ Resolve dimension for tables
    │   ├─ Get column name
    │   └─ Build SELECT expression
    │       ├─ For TimeDimension: Grammar.formatTimeBucket()
    │       └─ For regular Dimension: Column reference
    │
    ├─ addGroupBy()
    │   └─ Use same expression as SELECT
    │
    ├─ addDimensionFilters()
    │   ├─ Extract filters (only, except, where)
    │   └─ Generate WHERE clauses
    │
    └─ addOrderBy()
        └─ Order by dimension (for consistent result ordering)
    ↓
Database Grammar (DB-specific)
    └─ formatTimeBucket() → Database-specific SQL
        ├─ MySQL: DATE_FORMAT()
        ├─ PostgreSQL: date_trunc()
        ├─ SQL Server: DATEADD/DATEDIFF
        ├─ SQLite: strftime()
        └─ Other: Custom implementations
    ↓
Generated SQL Query
```

## 10. Testing Dimensions

From `tests/Unit/QueryTest.php`:

```php
test('software-join fallback matches database join output', function () {
    $results = Slice::query()
        ->metrics([...])
        ->dimensions([
            Dimension::make('status')->label('Order Status')
        ])
        ->get()
        ->toArray();
    
    expect($results)->toHaveCount(2);
    // Results grouped by status dimension
});
```

Dimensions work consistently across:
- Single-table queries
- Multi-table joins (database joins)
- Software join fallback (for drivers that don't support joins)
```
