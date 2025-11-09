# Slice Package - Architecture Diagrams & Visual Maps

## 1. Complete Class Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            PUBLIC API: Slice                                │
│  • metrics(array): Slice                                                    │
│  • dimensions(array): Slice                                                 │
│  • get(): ResultCollection                                                  │
└────────────────┬────────────────────────────────────────────────────────────┘
                 │
                 ├─→ normalizeMetrics() [Slice]
                 │   ├─→ Metric|MetricEnum|string
                 │   └─→ [{enum, table, metric, key}]
                 │
                 ├─→ [INJECTABLE] Registry
                 │   ├─ metricEnums[]
                 │   ├─ tables[]
                 │   ├─ metrics[]
                 │   └─ dimensions[]
                 │
                 ├─→ [INJECTABLE] QueryBuilder
                 │   ├─→ build(normalized, dimensions): QueryPlan
                 │   │   ├─→ extractTablesFromMetrics()
                 │   │   ├─→ [DEPENDENCY] DependencyResolver
                 │   │   │   ├─ splitByComputationStrategy()
                 │   │   │   ├─ groupByLevel()
                 │   │   │   └─ canComputeInDatabase()
                 │   │   ├─→ [DEPENDENCY] JoinResolver
                 │   │   │   ├─ buildJoinGraph()
                 │   │   │   ├─ findJoinPath() [BFS]
                 │   │   │   └─ applyJoins()
                 │   │   ├─→ [DEPENDENCY] DimensionResolver
                 │   │   │   ├─ resolveDimensionForTables()
                 │   │   │   ├─ validateGranularity()
                 │   │   │   └─ getColumnForTable()
                 │   │   ├─→ [DEPENDENCY] QueryDriver
                 │   │   │   ├─ createQuery(): QueryAdapter
                 │   │   │   └─ grammar(): QueryGrammar
                 │   │   └─→ RETURN: QueryPlan
                 │   │       ├─ DatabaseQueryPlan
                 │   │       │  └─ QueryAdapter
                 │   │       └─ SoftwareJoinQueryPlan
                 │   │          ├─ tablePlans[]
                 │   │          ├─ relations[]
                 │   │          └─ dimension/metric metadata
                 │
                 ├─→ [INJECTABLE] QueryExecutor
                 │   └─→ run(plan): array
                 │       ├─ DatabaseQueryPlan
                 │       │  └─ adapter.execute() [Direct]
                 │       └─ SoftwareJoinQueryPlan
                 │          ├─ Execute each tablePlan
                 │          ├─ performSoftwareJoins()
                 │          ├─ applyDimensionFilters()
                 │          └─ groupSoftwareResults()
                 │
                 └─→ [INJECTABLE] PostProcessor
                     └─→ process(rows, normalized): ResultCollection
                         ├─→ [DEPENDENCY] DependencyResolver
                         │   └─ splitByComputationStrategy()
                         └─→ processRowThroughSoftwareCTEs()
                             └─ evaluateExpression() [Computed metrics]
```

---

## 2. Data Transformation Pipeline

```
INPUT TYPES
    ↓
┌─────────────────────────────────────────────────────────────┐
│ 1. METRIC NORMALIZATION (Slice::normalizeMetrics)          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Metric[]          MetricEnum[]           string[]         │
│  ↓                 ↓                       ↓               │
│  Sum::make(...)   OrdersMetric::Revenue   "orders.revenue" │
│  ↓                 ↓                       ↓               │
│  table()          table() + get()         Registry lookup   │
│  ↓                 ↓                       ↓               │
│  NORMALIZED: [{enum, table, metric, key}, ...]             │
│                                                             │
└─────────────────────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. PLAN GENERATION (QueryBuilder::build)                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  normalized + dimensions                                   │
│  ↓                                                         │
│  extractTablesFromMetrics()   → [tables]                   │
│  splitByComputationStrategy() → {database, software}       │
│  groupByLevel()               → {0, 1, 2, ...}             │
│  ↓                                                         │
│  [DECISION TREE]                                           │
│  ├─ Multi-table + no CTEs + computed → SoftwareJoinPlan   │
│  ├─ Single-table + computed → DatabasePlan (simple)        │
│  ├─ Computed + supports CTE → DatabasePlan (CTEs)          │
│  └─ Fallback → SoftwareJoinPlan                            │
│  ↓                                                         │
│  PLAN: DatabaseQueryPlan | SoftwareJoinQueryPlan           │
│  ├─ QueryAdapter with complete query state               │
│  └─ Metadata for execution (relations, filters, aliases)   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. QUERY EXECUTION (QueryExecutor::run)                    │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  DatabaseQueryPlan                                         │
│  └─→ adapter.execute()                                    │
│      └─→ Raw database results                             │
│                                                             │
│  SoftwareJoinQueryPlan                                     │
│  └─→ For each tablePlan:                                 │
│      ├─ Execute individual query                          │
│      └─ Collect results                                   │
│  └─→ performSoftwareJoins()                               │
│      ├─ Index by FK columns                               │
│      ├─ Match and merge rows                              │
│      └─ joinedRows                                        │
│  └─→ applyDimensionFilters()                              │
│      └─ filteredRows                                      │
│  └─→ groupSoftwareResults()                               │
│      ├─ Group by dimensions                               │
│      ├─ SUM metrics in groups                             │
│      └─ Sorted results                                    │
│  ↓                                                         │
│  ROWS: array<{dimension_alias, metric_alias, ...}>        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. POST-PROCESSING (PostProcessor::process)                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  rows + normalized                                         │
│  ↓                                                         │
│  splitByComputationStrategy()                             │
│  ├─ database: Already computed, just normalize            │
│  └─ software: Needs post-execution evaluation             │
│  ↓                                                         │
│  For each software metric:                                │
│    groupByLevel() → Process in dependency order           │
│    ↓                                                       │
│    For each row:                                          │
│      evaluateExpression(expr, deps, row)                  │
│      ├─ Replace dep keys with values                      │
│      ├─ Evaluate using ExpressionLanguage or fallback     │
│      └─ Add result to row                                 │
│  ↓                                                         │
│  PROCESSED_ROWS: array<{all columns computed}>            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
    ↓
OUTPUT: ResultCollection
```

---

## 3. Query Builder Decision Tree (build method)

```
build(normalizedMetrics, dimensions)
│
├── extractTablesFromMetrics()
│  └─ tables = unique tables from metrics
│
├── splitByComputationStrategy()
│  ├─ database = metrics computable in SQL
│  └─ software = metrics needing post-execution
│
├─── CHECK: Multiple tables + !supportsJoins + computed?
│   ├─ YES → buildSoftwareJoinPlan()
│   └─ NO  → continue
│
├─── CHECK: !multiTable + supportsJoins + computed?
│   ├─ YES → buildWithCTEs()
│   └─ NO  → continue
│
├─── CHECK: supportsJoins?
│   ├─ YES → buildDatabasePlan()
│   └─ NO  → continue
│
└─── FALLBACK: buildSoftwareJoinPlan()
```

---

## 4. Software Join Execution Flow

```
SoftwareJoinQueryPlan Input
├─ primaryTable: 'orders'
├─ tablePlans: {
│    'orders': QueryAdapter(SELECT ... FROM orders),
│    'order_items': QueryAdapter(SELECT ... FROM order_items)
│  }
├─ relations: [
│    {from: 'orders', to: 'order_items', fromAlias: '__j0_id', toAlias: '__j0_oid'}
│  ]
├─ dimensionFilters: {'orders_country': {only: ['US']}}
└─ metricAliases: ['orders_revenue', 'order_items_total']
    │
    ├─── STEP 1: Execute per-table queries
    │    ├─ orders → [{__j0_id: 1, orders_revenue: 100, orders_country: 'US'}]
    │    └─ order_items → [{__j0_oid: 1, order_items_total: 50}]
    │
    ├─── STEP 2: Join in software
    │    ├─ pendingRelations = [orders → order_items]
    │    │
    │    └─ LOOP:
    │       ├─ orders joined? YES
    │       ├─ order_items joined? NO
    │       └─ Join orders[__j0_id=1] ← order_items[__j0_oid=1]
    │          └─ Result: [{__j0_id: 1, orders_revenue: 100, __j0_oid: 1, total: 50}]
    │
    ├─── STEP 3: Apply dimension filters
    │    ├─ Filter: orders_country IN ('US')
    │    └─ Keep all rows (all are 'US')
    │
    └─── STEP 4: Group and aggregate
         ├─ Group key = [orders_country] = 'US'
         ├─ For group 'US':
         │  ├─ orders_revenue = 100 (single value)
         │  └─ order_items_total = 50 (single value, sum if multiple)
         └─ Final row: {orders_country: 'US', orders_revenue: 100, order_items_total: 50}
             (join columns removed)

Output: array<rows>
```

---

## 5. Dimension Resolution Process

```
Dimension Instance
    ↓
TimeDimension::make('created_at')->daily()
    └─ Config: {column: 'created_at', granularity: 'daily'}
    │
    ├─ Requested by query
    │
    ├── DimensionResolver::resolveDimensionForTables(tables, dimension)
    │   │
    │   ├─ For each table:
    │   │  └─ Find matching TimeDimension in table->dimensions()
    │   │
    │   └─ Return: {
    │       'orders': {table: OrdersTable, dimension: TimeDimension(...)},
    │       'customers': {table: CustomersTable, dimension: TimeDimension(...)}
    │     }
    │
    ├─ Validate granularity (if time dimension)
    │
    └─ For each resolved dimension:
       ├─ SELECT: driver->grammar()->formatTimeBucket(table, 'created_at', 'daily')
       │          → DATE(created_at) for MySQL
       │          → date_trunc('day', created_at) for PostgreSQL
       │
       ├─ GROUP BY: Same expression
       │
       ├─ WHERE: Apply dimension.filters() (only, except, where)
       │
       └─ ORDER BY: Same expression

Result: Dimension values grouped and filtered
```

---

## 6. Registry Data Structure & Lookup

```
REGISTRATION PHASE (Boot time)

SliceServiceProvider
└─ Auto-discovery: app/Analytics/**/*Metric.php
   └─ For each MetricContract enum:
      └─ registry->registerMetricEnum(OrdersMetric::class)
         │
         └─ 1. Extract table info:
            │   OrdersMetric::Revenue->table() → OrdersTable
            │
            ├─ 2. Register table:
            │   tables['orders'] = OrdersTable instance
            │
            ├─ 3. Register enum class:
            │   metricEnums['orders'] = OrdersMetric::class
            │
            ├─ 4. Register each metric case:
            │   metrics['orders.revenue'] = {
            │       'aggregation': 'sum',
            │       'column': 'total',
            │       'enum_class': OrdersMetric::class,
            │       'enum_case': 'Revenue',
            │       'table': 'orders'
            │   }
            │
            └─ 5. Register table dimensions:
                dimensions['orders.created_at'] = {
                    'column': 'created_at',
                    'dimension_class': TimeDimension::class,
                    'table': 'orders'
                }


LOOKUP PHASE (Query time)

String Query: Slice::query()->metrics(['orders.revenue'])
    │
    └─ Slice::normalizeMetrics()
       └─ Is string? YES
          └─ registry->lookupMetric('orders.revenue')
             │
             ├─ getMetric('orders.revenue')
             │  └─ metrics['orders.revenue'] → metadata
             │
             ├─ Get enumClass: metadata['enum_class'] → OrdersMetric::class
             │
             ├─ Get caseName: metadata['enum_case'] → 'Revenue'
             │
             └─ Return: OrdersMetric::Revenue
                └─ Call ->table() and ->get() on enum
                   └─ Add to normalized array

Result: Fully resolved metric ready for building
```

---

## 7. CTE Generation Process (with CTEs support)

```
buildWithCTEs(tables, metrics, dimensions)
    │
    ├─ DependencyResolver::groupByLevel(metrics)
    │  │
    │  └─ levels = {
    │       0: [Sum('orders.total'), Count('orders.id')],
    │       1: [Computed('profit = revenue - cost')]
    │     }
    │
    ├─ Create query = driver->createQuery()
    │
    ├─ LEVEL 0: Base aggregations
    │  │
    │  ├─ cteQuery = buildBaseAggregationCTE(tables, [metrics_level_0], dimensions)
    │  │  │
    │  │  ├─ SELECT SUM(orders.total) as orders_revenue
    │  │  ├─ SELECT COUNT(orders.id) as orders_count
    │  │  ├─ SELECT DATE(created_at) as orders_created_at_daily
    │  │  ├─ JOIN (if multiple tables)
    │  │  ├─ GROUP BY DATE(created_at)
    │  │  └─ WHERE (dimension filters)
    │  │
    │  ├─ query->withExpression('level_0', cteQuery)
    │  │
    │  └─ previousCTE = 'level_0'
    │
    ├─ LEVEL 1: Computed metrics
    │  │
    │  ├─ cteQuery = buildComputedCTE('level_0', [metrics_level_1], dimensions)
    │  │  │
    │  │  ├─ SELECT * (from level_0)
    │  │  ├─ SELECT (orders_revenue - orders_cost) as orders_profit
    │  │  └─ NO GROUP BY (CTE limitation)
    │  │
    │  ├─ query->withExpression('level_1', cteQuery)
    │  │
    │  └─ previousCTE = 'level_1'
    │
    ├─ FINAL SELECT
    │  │
    │  ├─ query->from('level_1')
    │  ├─ query->select('*')
    │  └─ query->orderBy(dimension aliases)  [Not in CTEs - SQL Server limitation]
    │
    └─ RETURN: DatabaseQueryPlan(query)
       │
       └─ Final SQL (pseudocode):
          WITH level_0 AS (
              SELECT SUM(...) as revenue, ...
              FROM orders
              GROUP BY ...
          ),
          level_1 AS (
              SELECT *, (revenue - cost) as profit
              FROM level_0
          )
          SELECT * FROM level_1
          ORDER BY ...
```

---

## 8. Dependency Resolver: Computation Strategy Decision

```
splitByComputationStrategy(normalizedMetrics)
    │
    ├─ For each metric:
    │  │
    │  ├─ IF no dependencies:
    │  │  └─ database[]
    │  │
    │  ├─ ELSE IF canComputeInDatabase(metric):
    │  │  │
    │  │  ├─ Check: All dependencies exist in metrics?
    │  │  ├─ Check: All dependencies from SAME table?
    │  │  ├─ Check: No dependency needs software computation?
    │  │  │
    │  │  └─ ALL YES → database[]
    │  │     NO  → software[]
    │  │
    │  └─ ELSE:
    │     └─ software[]
    │
    └─ RETURN: {database: [...], software: [...]}


EXAMPLES:

1. Sum('orders.total')
   ├─ dependencies = []
   └─ → database (base metric)

2. Computed('revenue - cost')
   ├─ dependencies = ['orders.revenue', 'orders.cost']
   ├─ Both from table 'orders'
   └─ → database (can express in SQL)

3. Computed('order_revenue / product_sales')
   ├─ dependencies = ['orders.revenue', 'products.sales']
   ├─ From different tables
   └─ → software (cross-table, needs post-processing)

4. Computed('profit / COUNT(*)')
   ├─ dependencies = ['orders.profit', 'orders.count']
   ├─ Profit itself computed
   ├─ Check if profit can compute in database
   └─ If YES → database, else → software
```

---

## 9. JoinResolver: BFS Algorithm

```
findJoinPath(from: OrdersTable, to: CustomersTable, allTables)
    │
    ├─ from == to? NO → continue
    │
    ├─ INITIALIZE:
    │  ├─ queue = [[OrdersTable, []]]
    │  └─ visited = {orders: true}
    │
    ├─ LOOP (BFS):
    │  │
    │  ├─ Iteration 1:
    │  │  ├─ Pop: OrdersTable, currentPath = []
    │  │  ├─ Iterate relations:
    │  │  │  ├─ customers (BelongsTo)
    │  │  │  ├─ NOT visited
    │  │  │  ├─ newPath = [{from: orders, to: customers, relation: BelongsTo}]
    │  │  │  ├─ target = customers? YES
    │  │  │  └─ RETURN newPath
    │  │  │
    │  │  ├─ order_items (HasMany)
    │  │  ├─ NOT visited
    │  │  ├─ newPath = [{from: orders, to: order_items, relation: HasMany}]
    │  │  ├─ target = order_items? NO
    │  │  ├─ Mark visited: {orders, order_items}
    │  │  └─ Add to queue
    │  │
    │  ├─ Iteration 2:
    │  │  ├─ Pop: OrderItemsTable, currentPath = [{orders → order_items}]
    │  │  ├─ Iterate relations:
    │  │  │  ├─ products (BelongsTo)
    │  │  │  ├─ NOT visited
    │  │  │  ├─ newPath = [{orders → order_items}, {order_items → products}]
    │  │  │  └─ Continue...
    │  │
    │  └─ Until target found or queue empty
    │
    └─ RETURN: Path of joins to reach target

RESULT: Shortest path (by number of joins)
```

---

## 10. Query Execution Polymorphism

```
QueryExecutor::run(plan)
    │
    ├─ plan instanceof DatabaseQueryPlan
    │  │
    │  └─ FAST PATH:
    │     └─ plan->adapter()->execute()
    │        └─ Single database query
    │           └─ Return raw rows
    │
    └─ plan instanceof SoftwareJoinQueryPlan
       │
       └─ COMPLEX PATH:
          ├─ Execute each table independently
          ├─ Join in PHP using FK indexes
          ├─ Filter dimension values in PHP
          ├─ Group and aggregate in PHP
          └─ Return final rows

BENEFIT: Can support databases that don't support JOINs
        by falling back to PHP-level joins
```

---

## 11. Time Dimension Bucketing by Database

```
TimeDimension::make('created_at')->daily()
    │
    ├─ QueryBuilder::buildTimeDimensionSelect('orders', 'created_at', 'daily')
    │  │
    │  └─ driver->grammar()->formatTimeBucket('orders', 'created_at', 'daily')
    │
    ├─ MySQL Grammar:
    │  └─ "DATE(orders.created_at) as orders_created_at_daily"
    │     [Also supports: hourly, weekly, monthly via DATE_FORMAT]
    │
    ├─ PostgreSQL Grammar:
    │  └─ "date_trunc('day'::text, orders.created_at) as orders_created_at_daily"
    │     [Also supports: hour, week, month via date_trunc]
    │
    ├─ SQLite Grammar:
    │  └─ "date(orders.created_at) as orders_created_at_daily"
    │     [Complex: weekly uses complex strftime expression]
    │
    ├─ SQL Server Grammar:
    │  └─ "CAST(orders.created_at AS DATE) as orders_created_at_daily"
    │     [Complex: hourly uses DATEADD/DATEDIFF]
    │
    └─ Other Grammars: Firebird, SingleStore, MariaDB, etc.

RESULT: Database-agnostic time dimension support
```

---

