# Slice Package - Current Architecture Analysis

## 1. Overview: Query Execution Flow

```
User Code
    ↓
Slice::query() → metrics([...]) → dimensions([...]) → get()
    ↓
normalizeMetrics() [3 input types: Metric, MetricEnum, string]
    ↓
QueryBuilder::build(normalizedMetrics, dimensions)
    ├→ extractTablesFromMetrics()
    ├→ DependencyResolver::splitByComputationStrategy()
    └→ [SELECT PLAN BASED ON CAPABILITIES]
        ├→ DatabaseQueryPlan (single driver)
        └→ SoftwareJoinQueryPlan (cross-driver)
    ↓
QueryExecutor::run(plan)
    ├→ DatabaseQueryPlan → execute() via QueryAdapter
    └→ SoftwareJoinQueryPlan → software join logic + grouping
    ↓
PostProcessor::process(rows, normalizedMetrics)
    └→ Evaluate software-computed metrics
    ↓
ResultCollection
```

---

## 2. Class Hierarchy & Responsibilities

### 2.1 Entry Point: Slice Class
**File:** `src/Slice.php`

**Purpose:** Main query API - orchestrates the entire query flow

**Key Properties:**
- `QueryBuilder $builder` - Query plan builder
- `QueryExecutor $executor` - Query plan executor
- `PostProcessor $postProcessor` - Post-execution processing
- `DimensionResolver $dimensionResolver` - Dimension resolution
- `Registry $registry` - Metric/table/dimension registry
- `QueryDriver $driver` - Database driver abstraction

**Key Methods:**
```php
metrics(array $metrics): static
    - Accepts: MetricEnum[], Metric[], strings[]
    - Stores in $selectedMetrics for later normalization

dimensions(array $dimensions): static
    - Stores array of Dimension instances
    
get(): ResultCollection
    1. normalizeMetrics($selectedMetrics) → array<array{enum, table, metric, key}>
    2. QueryBuilder::build(normalized, dimensions) → QueryPlan
    3. QueryExecutor::run(plan) → array<rows>
    4. PostProcessor::process(rows, normalized) → ResultCollection
```

**Metric Normalization (normalizeMetrics):**
- **Input Types:**
  1. `Metric` instances (e.g., `Sum::make('orders.total')`)
     - Has `table()` method or resolved from column name
     - Direct use in query building
  2. `MetricEnum` instances (e.g., `OrdersMetric::Revenue`)
     - Call `->table()` to get Table class
     - Call `->get()` to get Metric definition
     - Wraps the Metric inside normalized array
  3. String keys (e.g., `'orders.revenue'`)
     - Lookup via `Registry::lookupMetric(string)`
     - Returns MetricEnum, then extract table and Metric

- **Output Format:**
```php
[
    'enum' => ?MetricEnum,      // null for direct Metric instances
    'table' => Table,            // The table this metric belongs to
    'metric' => Metric,          // The actual metric (Sum, Count, Computed, etc.)
    'key' => string,             // Metric key (e.g., 'orders_revenue')
]
```

---

### 2.2 Query Building: QueryBuilder Class
**File:** `src/Engine/QueryBuilder.php`

**Purpose:** Build query execution plans from normalized metrics and dimensions

**Key Methods:**

#### build(array $normalizedMetrics, array $dimensions): QueryPlan
**Decision Tree:**
```
build()
├→ extractTablesFromMetrics()
├→ DependencyResolver::splitByComputationStrategy()
│  └→ Returns {database: [], software: []}
├→ Check: Driver supports database joins && has computed metrics?
│  └→ buildSoftwareJoinPlan()
├→ Check: Driver supports CTEs && has computed metrics?
│  └→ buildWithCTEs() → DatabaseQueryPlan with CTEs
├→ Check: Single table OR driver supports joins?
│  └→ buildDatabasePlan() → DatabaseQueryPlan
└→ Fallback: buildSoftwareJoinPlan()
```

#### buildDatabasePlan(tables, metrics, dimensions): DatabaseQueryPlan
1. Create base query from primary table via `driver->createQuery()`
2. If multiple tables: `JoinResolver::buildJoinGraph()` + `applyJoins()`
3. `addMetricSelects()` - SELECT SUM/COUNT/AVG with aliases
4. `addDimensionSelects()` - SELECT dimensions with time bucketing
5. `addGroupBy()` - GROUP BY dimension columns
6. `addDimensionFilters()` - WHERE clauses from dimension.filters()
7. `addOrderBy()` - ORDER BY dimensions for consistency
8. Return `DatabaseQueryPlan($query)`

#### buildSoftwareJoinPlan(tables, metrics, dimensions): SoftwareJoinQueryPlan
1. Build join graph: `JoinResolver::buildJoinGraph()`
2. For each join in graph, create `SoftwareJoinRelation`:
   - Store FK/PK column aliases: `__join_0_orders_customers_customer_id`
   - Track relation type: belongs_to, has_many
3. For each table: Create separate `SoftwareJoinTablePlan`
   - Includes join columns in SELECT
   - Includes GROUP BY for join columns
   - Filters metrics to this table only
4. Collect dimension aliases: `__table_dimensionName_granularity`
5. Collect metric aliases: `table_metricName`
6. Collect dimension filters for post-execution filtering
7. Return `SoftwareJoinQueryPlan(primaryTable, tablePlans[], relations[], ...)`

#### buildWithCTEs(tables, metrics, dimensions): DatabaseQueryPlan
1. Group metrics by level: `DependencyResolver::groupByLevel()`
   - Level 0: Base metrics (Sum, Count, etc.)
   - Level 1+: Computed metrics depending on lower levels
2. For each level:
   - Level 0: `buildBaseAggregationCTE()` - aggregations + joins
   - Level 1+: `buildComputedCTE()` - SELECT * + computed expressions
3. Create CTEs: `query->withExpression('level_0', cteQuery0)`
4. Final SELECT: `query->from('level_1')->select('*')`
5. Add ORDER BY to final query (not CTEs - SQL Server limitation)
6. Return `DatabaseQueryPlan($query)`

#### Dimension Processing
- `addDimensionSelects()` - For each dimension:
  - Resolve to actual columns via `DimensionResolver`
  - If TimeDimension: Generate time bucket SQL via `driver->grammar()->formatTimeBucket()`
  - SELECT with alias: `DATE_FORMAT(...) as orders_created_at_daily`
- `addGroupBy()` - GROUP BY same expressions
- `addDimensionFilters()` - WHERE from dimension.filters()
- `addOrderBy()` - ORDER BY same expressions

#### Key Helpers:
- `extractTablesFromMetrics()` - Unique tables from metrics
- `addMetricSelects()` - Call metric's `applyToQuery()` method
- `filterMetricsForTable()` - Filter metrics for specific table
- `buildSoftwareJoinRelations()` - Parse join graph into relations
- `translateComputedExpression()` - Replace metric keys with aliases

---

### 2.3 Join Resolution: JoinResolver Class
**File:** `src/Engine/JoinResolver.php`

**Purpose:** Find and apply join paths between tables using BFS

**Key Methods:**

#### findJoinPath(Table $from, Table $to, allTables): ?array
**Algorithm: BFS (Breadth-First Search)**
```
1. If from == to: return []
2. Queue: [[from, []]]
3. Visited: {from.table() → true}
4. While queue not empty:
   a. Pop [currentTable, currentPath]
   b. For each relation in currentTable->relations():
      - Get relatedTable class
      - If not visited:
        - Create newPath = currentPath + [{from, to, relation}]
        - If relatedTable == target: return newPath
        - Add relatedTable to queue + visited
5. Return null (no path found)
```

**Returns:** `array<array{from: string, to: string, relation: mixed}>`
- Each element represents one JOIN operation
- `relation` is the actual relation object (BelongsTo, HasMany, etc.)

#### buildJoinGraph(tables): array
**Algorithm: Connect all tables to form a graph**
```
1. Start with tables[0] as connected
2. For each remaining table:
   a. Find ANY path from ANY connected table to this table
   b. Add all joins from that path to allJoins (avoiding duplicates)
   c. Mark this table as connected
3. Return ordered joins
```

#### applyJoins(QueryAdapter, joinPath): QueryAdapter
**For each join in path:**
```
if relation instanceof BelongsTo:
    JOIN table2 ON table1.fk = table2.pk
else if relation instanceof HasMany:
    JOIN table2 ON table1.pk = table2.fk
```

---

### 2.4 Query Execution: QueryExecutor Class
**File:** `src/Engine/QueryExecutor.php`

**Purpose:** Execute query plans and return normalized results

**Key Methods:**

#### run(QueryPlan $plan): array
```
if plan instanceof DatabaseQueryPlan:
    return plan->adapter()->execute()
    
if plan instanceof SoftwareJoinQueryPlan:
    return executeSoftwareJoinPlan(plan)
```

#### executeSoftwareJoinPlan(SoftwareJoinQueryPlan): array
**Step 1: Execute each table query**
```
foreach plan->tablePlans() as tableName → tablePlan:
    tableResults[tableName] = normalizeRows(tablePlan->adapter()->execute())
```

**Step 2: Perform software joins**
```
performSoftwareJoins(plan, tableResults) → joinedRows
- Start with primary table results
- While pending relations exist:
  - Find relation where one side is joined, other isn't
  - Call joinRows() to merge them
  - Remove relation from pending
```

**joinRows Algorithm (Inner Join)**
```
1. Index rightRows by rightAlias value:
   rightIndex[row[rightAlias]][] = row
2. For each leftRow:
   - Get key = leftRow[leftAlias]
   - For each matching rightRow in rightIndex[key]:
     - merged = leftRow + rightRow
     - Add to joined
```

**Step 3: Apply dimension filters**
```
applyDimensionFilters(joinedRows, dimensionFilters)
- For each row:
  - Check if each dimension filter passes:
    - only: value in allowed_values
    - except: value not in excluded_values
    - where: value matches operator comparison
```

**Step 4: Group and aggregate**
```
groupSoftwareResults(filteredRows, plan)
- Group by dimension aliases: JSON key = [dim1, dim2, ...]
- For each group:
  - Keep dimension values
  - SUM metric values from all rows in group
- Remove join alias columns
- Sort by dimension order
```

---

### 2.5 Post Processing: PostProcessor Class
**File:** `src/Engine/PostProcessor.php`

**Purpose:** Calculate software-computed metrics after database execution

**Key Methods:**

#### process(array $rows, array $normalizedMetrics): ResultCollection
```
1. Split metrics: splitByComputationStrategy()
   - database: metrics computable in SQL
   - software: metrics needing post-execution evaluation
   
2. If no software metrics:
   return normalizeMetricValues(rows, metrics)
   
3. If software metrics exist:
   For each row:
       processRowThroughSoftwareCTEs(row, softwareLevels)
   
4. Return ResultCollection(processedRows)
```

#### processRowThroughSoftwareCTEs(row, softwareLevels): array
```
processedRow = normalizeRowMetrics(row, allMetrics)

For each level in softwareLevels (0 = base, 1+ = computed):
    For each metric in level:
        if metric.computed:
            result = evaluateExpression(
                expression,      // e.g., "revenue - cost"
                dependencies,    // e.g., ["orders.revenue", "orders.cost"]
                processedRow     // Row with all values so far
            )
            processedRow[metric.key] = result
```

#### evaluateExpression(expression, dependencies, row): mixed
```
1. Build context dict: {orders_revenue: 100, orders_cost: 30}
   - Dependencies converted from "table.key" → "table_key"
   
2. Translate expression: "revenue - cost" → "orders_revenue - orders_cost"
   - Uses regex word boundary replacement
   
3. Safely evaluate:
   - Try Symfony ExpressionLanguage (if available)
   - Fallback to evaluateSimpleMathExpression()
   - Support: +, -, *, /, (), NULLIF()
   
4. Return result
```

---

### 2.6 Dependency Resolution: DependencyResolver Class
**File:** `src/Engine/DependencyResolver.php`

**Purpose:** Analyze metric dependencies for ordering and computation strategy

**Key Methods:**

#### resolve(normalizedMetrics): array
**DFS (Depth-First Search) topological sort**
```
For each metric:
    resolveDependencies(metric, graph, resolved, unresolved)
    - If already resolved: skip
    - If in unresolved (circular): throw error
    - Recursively resolve dependencies first
    - Add to resolved
Return ordered metrics
```

#### splitByComputationStrategy(normalizedMetrics): {database: [], software: []}
```
For each metric:
    if no dependencies:
        → database
    else if canComputeInDatabase(metric, allMetrics):
        → database
    else:
        → software
        
canComputeInDatabase checks:
- All dependency metrics exist
- All dependencies from SAME table
- No dependency needs software computation
```

#### groupByLevel(normalizedMetrics): array<int, array>
```
For each metric:
    level = calculateLevel(metric, allMetrics, visited)
    levels[level][] = metric

calculateLevel(metric):
    if no dependencies: return 0
    return max(dependency levels) + 1
    
Result:
    levels[0] → base metrics (Sum, Count, etc.)
    levels[1] → computed depending on level 0
    levels[2] → computed depending on level 1
    etc.
```

---

### 2.7 Dimension Resolution: DimensionResolver Class
**File:** `src/Engine/DimensionResolver.php`

**Purpose:** Map dimensions to table columns and validate constraints

**Key Methods:**

#### resolveDimensionForTables(tables[], dimension): array<string, {table, dimension}>
```
For each table in tables:
    For each tableDimension in table->dimensions():
        if tableDimension class matches dimension class:
            resolved[tableName] = {table, tableDimension}
            break
Return resolved
```

#### validateGranularity(resolvedDimensions, requestedDimension): void
```
For TimeDimension:
    Check that all tables support the requested granularity
    (e.g., can't request hourly if table only has daily data)
```

#### getColumnForTable(table, dimension): string
```
For each tableDimension in table->dimensions():
    if matches dimension class:
        return tableDimension->toArray()['column']
Throw error if not found
```

---

### 2.8 Registry: Registry Class
**File:** `src/Support/Registry.php`

**Purpose:** Store and lookup metrics, tables, and dimensions via auto-discovery

**Data Structures:**

```php
protected array $metricEnums = [
    'orders' => OrdersMetric::class,      // table → enum class
    'ad_spend' => AdSpendMetric::class,
];

protected array $tables = [
    'orders' => OrdersTable instance,     // table name → Table object
    'customers' => CustomersTable instance,
];

protected array $metrics = [
    'orders.revenue' => [                 // table.metricName → metadata
        'aggregation' => 'sum',
        'column' => 'total',
        'enum_class' => OrdersMetric::class,
        'enum_case' => 'Revenue',
        'table' => 'orders',
        ...
    ],
    'orders.count' => [...],
    'ad_spend.spend' => [...],
];

protected array $dimensions = [
    'orders.created_at' => [              // table.dimensionName → metadata
        'column' => 'created_at',
        'dimension_class' => TimeDimension::class,
        'table' => 'orders',
        ...
    ],
    'orders.country' => [...],
];
```

**Key Methods:**

#### registerMetricEnum(string $enumClass): void
```
1. Get first enum case
2. Call case->table() to get Table
3. Register table in $tables
4. Register enum in $metricEnums[tableName]
5. For each enum case:
   - key = "table.metricName"
   - Store in $metrics[key] with metadata
6. For each table dimension:
   - key = "table.dimensionName"
   - Store in $dimensions[key] with metadata
```

#### registerTable(Table $table): void
```
1. Store in $tables[tableName]
2. For each table dimension:
   - key = "table.dimensionName"
   - Store in $dimensions[key]
```

#### lookupMetric(string $key): ?MetricEnum
```
1. getMetric(key) → metadata or null
2. enumClass = metadata['enum_class']
3. caseName = metadata['enum_case']
4. Return enumClass::$caseName (enum case)
```

**Auto-Discovery (in SliceServiceProvider):**
```
Scan: app/Analytics/**/*Metric.php
For each file:
    If class implements MetricContract:
        registry->registerMetricEnum(enumClass)
```

---

### 2.9 Query Driver: QueryDriver Interface & LaravelQueryDriver
**File:** `src/Contracts/QueryDriver.php` and `src/Engine/Drivers/LaravelQueryDriver.php`

**Purpose:** Abstract database differences, provide adapter factory

**Interface Methods:**
```php
name(): string                              // "mysql", "pgsql", "sqlite", etc.
createQuery(?string $table = null): QueryAdapter
grammar(): QueryGrammar                    // For SQL fragment generation
supportsDatabaseJoins(): bool               // Can perform JOINs?
supportsCTEs(): bool                        // Can use WITH clauses?
```

**LaravelQueryDriver Implementation:**
```
name() → Detect from config('database.default')

createQuery(table) → LaravelQueryAdapter wrapping Illuminate\Database\Query\Builder

grammar() → Auto-detect based on driver:
    - mysql → MySqlGrammar
    - pgsql → PostgresGrammar
    - sqlite → SqliteGrammar
    - sqlsrv → SqlServerGrammar
    - mariadb → MariaDbGrammar
    - singlestore → SingleStoreGrammar
    - firebird → FirebirdGrammar

supportsDatabaseJoins() → true for all standard drivers

supportsCTEs() → true for MySQL 8+, PostgreSQL, SQL Server, Firebird
                → false for SQLite < 3.8, etc.
```

---

### 2.10 Query Adapter: QueryAdapter Interface
**File:** `src/Contracts/QueryAdapter.php`

**Purpose:** Minimal abstraction over database query builders

**Key Methods:**
```php
selectRaw(string $expression): void         // SELECT expr
join(table, first, operator, second): void  // JOIN
groupBy(column): void                       // GROUP BY col
groupByRaw(expression): void                // GROUP BY expr
whereIn(column, values): void               // WHERE IN
whereNotIn(column, values): void            // WHERE NOT IN
where(column, operator, value): void        // WHERE col op val
execute(): array                            // Execute & return rows
getDriverName(): string                     // "mysql", "pgsql", etc.
getNative(): mixed                          // Access underlying builder
withExpression(name, query): void           // WITH cte AS (...)
from(table): void                           // FROM table
select(columns): void                       // SELECT cols
supportsCTEs(): bool                        // Driver capability check
orderBy(column, direction = 'asc'): void   // ORDER BY
orderByRaw(expression): void                // ORDER BY expr
```

---

### 2.11 Query Grammar: QueryGrammar Classes
**Purpose:** Database-specific SQL generation for time bucketing

**Key Method:**
```php
formatTimeBucket(string $table, string $column, string $granularity): string
```

**Implementations:**
- `MySqlGrammar`: DATE_FORMAT, DATE, DATE_FORMAT('%Y-%u'), DATE_FORMAT('%Y-%m')
- `PostgresGrammar`: date_trunc('hour', col), date_trunc('day', col), etc.
- `SqliteGrammar`: strftime('%Y-%m-%d %H:00:00', col), date(col), etc.
- `SqlServerGrammar`: DATEADD/DATEDIFF patterns
- `FirebirdGrammar`: EXTRACT patterns

---

## 3. Query Plan Hierarchy

### QueryPlan (Interface)
```
QueryPlan
├── DatabaseQueryPlan
│   └── Holds: QueryAdapter
│       └── Execute directly via adapter.execute()
│
└── SoftwareJoinQueryPlan
    ├── Holds: primaryTable (string)
    ├── Holds: tablePlans (array<table → SoftwareJoinTablePlan>)
    ├── Holds: relations (array<SoftwareJoinRelation>)
    ├── Holds: dimensionOrder (array<string> aliases)
    ├── Holds: metricAliases (array<string> aliases)
    ├── Holds: dimensionFilters (array<alias → {only, except, where}>)
    └── Holds: joinAliases (array<string> join column aliases)
```

### SoftwareJoinTablePlan
```
Holds per table:
- tableName (string)
- adapter (QueryAdapter) - The individual table query
- isPrimary (bool) - Is this the main table?
```

### SoftwareJoinRelation
```
Holds one relation:
- relationKey (string) - "table1->table2"
- from (string) - Source table
- to (string) - Target table
- type (string) - "belongs_to" or "has_many"
- fromAlias (string) - "__join_0_orders_customers_customer_id"
- toAlias (string) - "__join_0_customers_customers_id"
```

---

## 4. Data Flow Examples

### Example 1: Simple Single-Table Query
```
User Code:
    Slice::query()
        ->metrics([OrdersMetric::Revenue])
        ->dimensions([TimeDimension::make('created_at')->daily()])
        ->get()

Step 1: normalizeMetrics()
    OrdersMetric::Revenue
    → table() → OrdersTable
    → get() → Sum::make('orders.total')->currency('USD')
    Output: [{
        enum: OrdersMetric::Revenue,
        table: OrdersTable,
        metric: Sum(...),
        key: "orders_revenue"
    }]

Step 2: QueryBuilder::build()
    - extractTablesFromMetrics() → [OrdersTable]
    - splitByComputationStrategy() → {database: [metric], software: []}
    - Single table + database metrics → buildDatabasePlan()
    - Create: query = driver->createQuery('orders')
    - addMetricSelects() → SELECT SUM(total) as orders_revenue
    - addDimensionSelects() → SELECT DATE(created_at) as orders_created_at_daily
    - addGroupBy() → GROUP BY DATE(created_at)
    - Return DatabaseQueryPlan(query)

Step 3: QueryExecutor::run()
    - DatabaseQueryPlan → plan->adapter()->execute()
    - Executes: SELECT SUM(total) as orders_revenue, DATE(created_at) as orders_created_at_daily 
                FROM orders 
                GROUP BY DATE(created_at)
    - Returns: [{orders_revenue: 1000, orders_created_at_daily: '2024-01-01'}, ...]

Step 4: PostProcessor::process()
    - No software metrics → just normalize values
    - Return ResultCollection(rows)
```

### Example 2: Multi-Table Query with Software Join
```
User Code:
    Slice::query()
        ->metrics([
            OrdersMetric::Revenue,      // From orders table
            Sum::make('order_items.total')  // From order_items table
        ])
        ->dimensions([CountryDimension::make('country')])
        ->get()

Step 1: normalizeMetrics()
    [{
        enum: OrdersMetric::Revenue,
        table: OrdersTable,
        metric: Sum('orders.total'),
        key: "orders_revenue"
    }, {
        enum: null,
        table: OrderItemsTable,
        metric: Sum('order_items.total'),
        key: "order_items_total"
    }]

Step 2: QueryBuilder::build()
    - extractTablesFromMetrics() → [OrdersTable, OrderItemsTable]
    - Driver doesn't support joins → buildSoftwareJoinPlan()
    
    - buildJoinGraph():
        - orders → order_items (via has_many)
        - Return [{from: 'orders', to: 'order_items', relation: HasMany(...)}]
    
    - buildSoftwareJoinRelations():
        - Create SoftwareJoinRelation(
            relationKey: "orders->order_items",
            from: "orders",
            to: "order_items",
            type: "has_many",
            fromAlias: "__join_0_orders_order_items_id",  // orders.id
            toAlias: "__join_0_order_items_order_items_order_id"  // order_items.order_id
        )
        - Join columns: orders.id, order_items.order_id
    
    - For orders table:
        - SELECT: __join_0_orders_order_items_id, orders_revenue
        - GROUP BY: orders.id
    
    - For order_items table:
        - SELECT: __join_0_order_items_order_items_order_id, order_items_total
        - GROUP BY: order_items.order_id
    
    - Return SoftwareJoinQueryPlan(
        primaryTable: 'orders',
        tablePlans: {
            'orders' → SoftwareJoinTablePlan(...),
            'order_items' → SoftwareJoinTablePlan(...)
        },
        relations: [SoftwareJoinRelation(...)],
        dimensionOrder: ["orders_country"],
        metricAliases: ["orders_revenue", "order_items_total"],
        dimensionFilters: {...}
    )

Step 3: QueryExecutor::run()
    - SoftwareJoinQueryPlan → executeSoftwareJoinPlan()
    
    - Execute orders: SELECT __join_0_orders_order_items_id as j0, 
                             orders_revenue, 
                             orders_country
                      FROM orders 
                      GROUP BY orders.id
        Result: [{j0: 1, orders_revenue: 100, orders_country: 'US'}, ...]
    
    - Execute order_items: SELECT __join_0_order_items_order_items_order_id as j1,
                                   order_items_total
                            FROM order_items
                            GROUP BY order_items.order_id
        Result: [{j1: 1, order_items_total: 50}, {j1: 1, order_items_total: 30}, ...]
    
    - performSoftwareJoins():
        - Index order_items by j1: {1: [{j1: 1, total: 50}, {j1: 1, total: 30}]}
        - For each orders row (j0=1): Find matches in order_items (j1=1)
        - Merge: {j0: 1, orders_revenue: 100, country: 'US', j1: 1, total: 50}
        - Merge: {j0: 1, orders_revenue: 100, country: 'US', j1: 1, total: 30}
    
    - groupSoftwareResults():
        - Group by [orders_country]: 'US' → [{orders_revenue: 100, order_items_total: 80}, ...]
        - Remove join columns (j0, j1)
        - Sum metrics within groups: order_items_total = 50 + 30 = 80

Step 4: PostProcessor::process()
    - Return ResultCollection with final results
```

---

## 5. Metric Types & Processing

### Aggregation Metrics (Computed in Database)
Examples: `Sum`, `Count`, `Avg`, `Min`, `Max`

**Processing:**
1. Implement `DatabaseMetric` interface
2. Have `applyToQuery()` method
3. Called by `QueryBuilder::addMetricSelects()`
4. Executed in database query
5. Results returned from `execute()`

### Computed Metrics (Post-Processed)
Example: `Computed::make('revenue - cost')`

**Structure:**
```php
[
    'computed' => true,
    'expression' => 'revenue - cost',
    'dependencies' => ['orders.revenue', 'orders.cost']
]
```

**Processing Path:**
1. Skipped in `addMetricSelects()` (computed flag)
2. Dependencies executed as base metrics
3. Post-execution: `PostProcessor::evaluateExpression()`
4. Expression evaluated using dependency values
5. Result added to row

**Computation Strategy:**
- **Database:** If all dependencies from same table + same database
- **Software:** If dependencies cross tables or databases

---

## 6. Key Design Patterns

### Pattern 1: Strategy Selection
```
QueryBuilder::build() selects execution strategy based on:
- Driver capabilities (joins, CTEs)
- Metric types (computed vs aggregation)
- Table count (single vs multi)
- Cross-driver status

Result: Pluggable plan types (Database, SoftwareJoin)
```

### Pattern 2: Plan Objects
```
Query plans encapsulate execution metadata:
- DatabaseQueryPlan: Thin wrapper (just the adapter)
- SoftwareJoinQueryPlan: Rich metadata (relations, filters, aliases)

Executor polymorphically handles each type:
- DatabaseQueryPlan → direct execute()
- SoftwareJoinQueryPlan → custom join + group logic
```

### Pattern 3: Lazy Resolution
```
1. Metrics can be:
   - Enum (resolved to table at normalization)
   - Metric (resolved to table via table() method)
   - String (resolved via registry at normalization)
   
2. Dimensions can be:
   - Class instances with parameters (daily(), currency(), etc.)
   - Resolved to table columns at query build time

Benefit: Type-safe enums + flexible string queries via registry
```

### Pattern 4: Multi-Stage Processing
```
normalizeMetrics (input polymorphism)
    ↓
QueryBuilder (plan generation)
    ↓
QueryExecutor (execution)
    ↓
PostProcessor (computed metrics)
    ↓
ResultCollection (output)

Each stage can be independently extended/replaced
```

### Pattern 5: Abstraction Layers
```
Slice → Public API
    ↓
Engine (QueryBuilder, QueryExecutor, PostProcessor)
    ↓
Contracts (QueryDriver, QueryAdapter)
    ↓
Drivers (LaravelQueryDriver, Custom Drivers)
    ↓
Database

Benefit: Any database can be supported by implementing contracts
```

---

## 7. Current Limitations & Design Gaps

### Known Issues:
1. **Computed metrics on cross-table data**
   - PostProcessor evaluates in software
   - May not be correct for aggregation scenarios
   - Example: `(total_revenue / total_orders)` won't work correctly

2. **Registry lookup timing**
   - Enums must be auto-discovered at boot
   - Dynamic metric registration not supported

3. **Circular dimension dependencies**
   - No validation for impossible filter combinations

4. **CTE ordering (SQL Server)**
   - CTEs can't have ORDER BY (need TOP/OFFSET)
   - ORDER BY moved to final query

5. **Software join limitations**
   - Only supports inner joins
   - No left/right join support
   - Cartesian products possible with multi-valued dimensions

---

## 8. Extension Points

### Custom Aggregations:
```php
class Median implements DatabaseMetric {
    public function applyToQuery(QueryAdapter $adapter, QueryDriver $driver, 
                                  string $table, string $alias) {
        // Add custom SQL
    }
}
```

### Custom Drivers:
```php
class ClickhouseGrammar extends QueryGrammar {
    public function formatTimeBucket(...) {
        // Clickhouse-specific syntax
    }
}

LaravelQueryDriver::extend('clickhouse', ClickhouseGrammar::class);
```

### Custom Dimensions:
```php
class GeoHashDimension extends Dimension {
    // Custom dimension logic
}
```

---

## 9. Summary: Key Takeaways for Refactoring

### Architecture is Based On:
1. **Polymorphic input** (Metric, MetricEnum, string)
2. **Plan-based execution** (strategy selection deferred until build time)
3. **Multi-stage processing** (normalize → build → execute → post-process)
4. **Adapter pattern** (QueryDriver, QueryAdapter abstractions)
5. **Registry system** (auto-discovery + string-based lookups)

### Strengths:
- Flexible metric input types
- Clean separation of concerns
- Plan objects enable testability
- Driver abstraction for multi-database support
- Soft join fallback for unsupported drivers

### Refactor Considerations:
- Registry timing and lifecycle
- Computed metric evaluation accuracy
- Soft join cartesian product issues
- CTE vs materialization tradeoffs
- Connection between enums and tables (table method overhead)

