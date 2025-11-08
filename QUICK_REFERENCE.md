# Slice Architecture - Quick Reference & Refactoring Guide

## Key Statistics

### Files Analyzed
- **Core Classes:** 9 main engine files
- **Contracts:** 6 interface definitions
- **Plans:** 5 query plan classes
- **Total Components:** 40+ classes and interfaces

### Code Metrics
- **Query Flow Stages:** 4 (normalize → build → execute → post-process)
- **Strategy Patterns Used:** 3 (Plan selection, metric normalization, computation strategy)
- **Abstraction Layers:** 5 (Slice → Builder → Driver → Grammar → Database)
- **Database Support:** 7 drivers (MySQL, PostgreSQL, SQLite, SQL Server, MariaDB, SingleStore, Firebird)

---

## Critical Classes & Their Role

### Must-Know Classes (For Any Refactor)

1. **Slice** (`src/Slice.php`) [10 lines of actual logic]
   - Entry point
   - Normalizes inputs
   - Orchestrates the pipeline
   
2. **QueryBuilder** (`src/Engine/QueryBuilder.php`) [700 lines]
   - Decision engine for plan selection
   - Dimension resolution
   - Query construction
   - **Complexity:** HIGH
   
3. **QueryExecutor** (`src/Engine/QueryExecutor.php`) [300 lines]
   - Polymorphic execution (Database vs SoftwareJoin)
   - Software join algorithm
   - Row grouping and filtering
   - **Complexity:** MEDIUM-HIGH
   
4. **PostProcessor** (`src/Engine/PostProcessor.php`) [270 lines]
   - Computed metric evaluation
   - Expression parsing and evaluation
   - **Complexity:** MEDIUM
   
5. **Registry** (`src/Support/Registry.php`) [170 lines]
   - Central data store
   - Auto-discovery
   - String-based lookups
   - **Complexity:** LOW

### Helper Classes

6. **DependencyResolver** (`src/Engine/DependencyResolver.php`) [240 lines]
   - Topological sorting (DFS)
   - Computation strategy classification
   - Dependency level calculation
   - **Complexity:** MEDIUM
   
7. **JoinResolver** (`src/Engine/JoinResolver.php`) [133 lines]
   - BFS pathfinding
   - Join graph construction
   - **Complexity:** MEDIUM
   
8. **DimensionResolver** (`src/Engine/DimensionResolver.php`) [87 lines]
   - Table-to-dimension mapping
   - Granularity validation
   - **Complexity:** LOW
   
9. **QueryDriver/QueryAdapter** (Contracts)
   - Database abstraction
   - Critical for multi-db support
   - **Complexity:** MEDIUM

---

## Key Data Structures

### Normalized Metric Format
```php
// Output of Slice::normalizeMetrics()
[
    [
        'enum' => MetricEnum|null,              // Original enum (if applicable)
        'table' => Table,                       // Table instance
        'metric' => Metric,                     // Sum/Count/Avg/Computed
        'key' => string,                        // Unique identifier
    ],
    ...
]
```

**Why?** Unifies 3 input types (Metric, MetricEnum, string) into single format for rest of pipeline.

### Query Plan Structure
```php
// Abstract pattern
QueryPlan {
    // Base: Only queryAdapter
    DatabaseQueryPlan {
        adapter: QueryAdapter
    }
    
    // Extended: Rich metadata for manual joins
    SoftwareJoinQueryPlan {
        primaryTable: string
        tablePlans: {table → SoftwareJoinTablePlan}
        relations: SoftwareJoinRelation[]
        dimensionOrder: string[]
        metricAliases: string[]
        dimensionFilters: {alias → filter}
        joinAliases: string[]
    }
}
```

**Why?** Encapsulates all execution metadata so executor doesn't need to rebuild it.

### Dependency Graph Structure
```php
// Input: normalized metrics
// Output of groupByLevel()
[
    0 => [Sum('orders.total'), Count('orders.id')],        // Base metrics
    1 => [Computed('revenue - cost')],                      // Depends on level 0
    2 => [Computed('profit / revenue')],                    // Depends on level 0-1
]
```

**Why?** Enables layered CTE generation and soft metric evaluation.

---

## Critical Flows & Decision Points

### Flow 1: Query Normalization
```
Input: metrics(array)
    ├─ Metric instance → extract table + use directly
    ├─ MetricEnum → call table() + get()
    └─ String → Registry::lookupMetric()
Output: Unified normalized array
```

**Decision Point:** How to resolve strings without booting entire Registry?

### Flow 2: Plan Selection (QueryBuilder::build)
```
Inputs: normalized metrics, dimensions

Check 1: Multiple tables + no DB joins + has computed metrics?
    → SoftwareJoinPlan (Fallback for unsupported databases)

Check 2: Single table + computed metrics?
    → DatabasePlan with CTEs (if supported)

Check 3: Simple aggregations?
    → DatabasePlan (simple, direct execution)

Fallback: SoftwareJoinPlan
```

**Decision Point:** What if CTEs not available? (Currently tries soft join)

### Flow 3: Software Join Execution
```
Step 1: Execute each table query independently
Step 2: Join using FK columns as indexes
Step 3: Filter by dimension values
Step 4: Group and sum metrics
```

**Decision Point:** Only supports INNER joins - what about LEFT joins?

### Flow 4: Computed Metric Evaluation
```
Is canComputeInDatabase()?
    → Use CTE or SQL expressions
    → Database handles it

Else (cross-table):
    → PostProcessor::evaluateExpression()
    → For each row: evaluate math expression
    → Replace dependency keys with values
```

**Decision Point:** How to validate cross-table computed metrics are semantically correct?

---

## Method Chaining & Immutability

```php
// Current API
$slice = Slice::query();
$slice->metrics([...])->dimensions([...])->get();

// NOT immutable - modifies $selectedMetrics internally
// Issues for refactor:
// - Can't reuse same Slice instance for multiple queries
// - No ability to branch queries
// - Testing is harder (need fresh instance each time)
```

**Refactor Consideration:** Should this return new instances? (Immutable builder pattern)

---

## Performance Considerations

### Bottlenecks in Current Design

1. **JoinResolver::findJoinPath() - BFS**
   - Time: O(V + E) where V = tables, E = relations
   - Runs for EACH unjoined table
   - Could cache results

2. **Software joins - Row indexing**
   - For N rows in table1, M rows in table2
   - Time: O(M) to index, O(N * M) to join worst-case
   - Memory: O(M) to store index
   - Could optimize with sorted indexes for range queries

3. **DependencyResolver - DFS topological sort**
   - Time: O(V + E) where V = metrics, E = dependencies
   - Runs multiple times: resolve(), groupByLevel(), splitByComputationStrategy()
   - Could cache

4. **Dimension Resolution - Table lookup**
   - Linear scan through each table's dimensions
   - Time: O(T * D) where T = tables, D = dims per table
   - Could use index

### Memory Overhead

1. **Normalized metrics** - Stored in memory with full metadata
2. **Query plans** - SoftwareJoinPlan stores complete relation/filter metadata
3. **Software join results** - All rows in memory during join phase
4. **Caches** - No caching layer currently

---

## Testing Gaps & Edge Cases

### What's NOT covered
1. Circular dependencies in computed metrics (blocked at runtime)
2. Missing dimension on table (thrown at runtime)
3. Missing relation path between tables (returns empty plan?)
4. Complex expressions in computed metrics (only basic math supported)
5. Very large result sets with software joins (memory issues)
6. Timezone handling in time dimensions (not mentioned)
7. NULL handling in aggregations (varies by DB)
8. Unicode/collation in string grouping (DB-specific)

---

## Registry Auto-Discovery Mechanism

### How It Works

```
Boot Time:
    SliceServiceProvider loads
        ↓
    Scan app/Analytics/**/*Metric.php
        ↓
    For each file:
        - Check if class implements MetricContract
        - Call registry->registerMetricEnum(ClassName)
        - Extract enum cases + dimensions
        - Store in registry arrays
    
Query Time:
    If string metric:
        ↓
    registry->lookupMetric('table.metricName')
        ↓
    Return: MetricEnum case
```

### Issues with Current Approach

1. **Hard-coded path:** `app/Analytics/**/*Metric.php`
   - What if app structure is different?
   - No way to configure scan path
   
2. **Enum-only discovery:**
   - Direct Metric classes not registered
   - String lookups won't work for them

3. **Timing:** 
   - Must happen at boot
   - Can't dynamically add metrics later

4. **File-based:**
   - Doesn't work with dynamically loaded classes
   - Doesn't work with external packages

---

## Enum vs Direct Metrics Trade-offs

### Enum Approach (Current)
```php
enum OrdersMetric: string implements MetricContract {
    case Revenue = 'revenue';
    
    public function table(): Table { return new OrdersTable(); }
    public function get(): Metric { 
        return Sum::make('orders.total')->currency('USD');
    }
}

// Usage
->metrics([OrdersMetric::Revenue])     // Type-safe
->metrics(['orders.revenue'])          // String-based (via registry)
```

**Pros:**
- Type-safe (IDE autocomplete)
- Convention-based (all metrics in one place per table)
- Enum cases provide documentation

**Cons:**
- Tight coupling enum ↔ table
- Registry lookup overhead for strings
- Every metric needs an enum case

### Direct Metrics Approach
```php
// Usage
->metrics([
    Sum::make('orders.total')->currency('USD'),
    Count::make('orders.id'),
])
```

**Pros:**
- No Registry needed
- Flexible composition
- No tight coupling

**Cons:**
- Less discoverable
- No type-safety
- Each metric must have table() method

---

## The Table Class Role

### What Tables Provide
```php
class OrdersTable extends Table {
    protected string $table = 'orders';
    
    public function dimensions(): array {
        // Declares which dimensions this table supports
        return [
            TimeDimension::class => TimeDimension::make('created_at'),
            CountryDimension::class => CountryDimension::make('country'),
        ];
    }
    
    public function relations(): array {
        // FK relationships for joins
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id'),
        ];
    }
}
```

### Current Issues

1. **Instantiation overhead:**
   - `new OrdersTable()` called multiple times
   - No singleton/cache

2. **Table method must be called:**
   - Every metric enum calls `->table()`
   - Every normalized metric stores table instance
   - Could cache or use string names instead

3. **Dimension declaration in Table:**
   - Couples dimension list to Table
   - Alternative: Separate DimensionRegistry?

---

## Expression Evaluation Security

### Current Implementation
```php
// In PostProcessor::safeEvaluate()

// Option 1: Symfony ExpressionLanguage
$expressionLanguage->evaluate($expression, $context)

// Option 2: Fallback - Simple math parser
preg_replace() + eval()  // ⚠️ Potentially unsafe
```

### Concerns
1. User-provided expressions evaluated with eval()
2. Context values come from user input (dimensions, filters)
3. No AST-based safe parsing for fallback

### Better Approach
- Always use Symfony ExpressionLanguage
- Or: Implement safe math parser with NO eval()
- Whitelist allowed functions

---

## Current Architecture Diagram Summary

```
PUBLIC API
    ↓ normalizeMetrics(3 input types)
NORMALIZED METRICS
    ↓ build(strategy selection)
QUERY PLAN (Database or SoftwareJoin)
    ↓ run(polymorphic execution)
ROWS
    ↓ process(computed metrics)
RESULT COLLECTION
```

**Decoupling:** Each stage takes only what it needs from previous stage

**Extensibility:** Can swap:
- Input normalizer (custom metric types)
- Plan types (new strategies)
- Executors (new implementations)
- Post-processors (custom computed metrics)

---

## Recommended Refactor Approach

### Phase 1: Stabilize Foundation
1. Extract table/dimension lookup to separate Registry
2. Add caching to DependencyResolver, JoinResolver, DimensionResolver
3. Add unit tests for each component
4. Document current behavior

### Phase 2: Improve Metrics
1. Separate Enum-based from Direct metrics
2. Improve Registry to support direct metrics
3. Add computed metric validation
4. Support computed metrics on cross-table data

### Phase 3: Enhance Joins
1. Add LEFT/RIGHT join support to soft joins
2. Optimize soft join algorithm (sorted index, hash join)
3. Add query plan caching
4. Add query result caching

### Phase 4: Expression Evaluation
1. Remove eval() usage
2. Implement proper math expression parser
3. Add more functions (AVG, MIN, MAX in post-process)
4. Better error reporting

### Phase 5: Multi-Database
1. Test all 7 drivers thoroughly
2. Add dialect-specific optimizations
3. Support custom drivers (plugin system)

---

## File Organization for Refactoring

### Core Pipeline (Must Keep Working)
- `src/Slice.php`
- `src/Engine/QueryBuilder.php`
- `src/Engine/QueryExecutor.php`
- `src/Engine/PostProcessor.php`
- `src/Contracts/Metric.php`
- `src/Contracts/MetricContract.php`
- `src/Contracts/QueryDriver.php`
- `src/Contracts/QueryAdapter.php`

### Supporting Components (Can Refactor Internally)
- `src/Engine/DependencyResolver.php`
- `src/Engine/JoinResolver.php`
- `src/Engine/DimensionResolver.php`
- `src/Support/Registry.php`

### Query Plans (May Need Changes)
- `src/Engine/Plans/*.php`

### Grammar/Drivers (Add-only)
- `src/Engine/Grammar/*.php`
- `src/Engine/Drivers/*.php`

---

## Glossary

- **Normalized Metric:** Standard format with enum, table, metric, key
- **Query Plan:** Strategy for execution (Database or SoftwareJoin)
- **Software Join:** Joining tables in PHP instead of SQL
- **CTE:** Common Table Expression (WITH clause)
- **Computed Metric:** Metric depending on other metrics
- **Dimension:** Way to slice/group data (time, geography, etc.)
- **Granularity:** Time bucketing level (daily, hourly, monthly)
- **Table:** Database table definition with dimensions and relations
- **Grammar:** Database-specific SQL generation
- **Registry:** Central store for metrics, tables, dimensions

---

