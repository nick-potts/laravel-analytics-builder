# Slice Join Resolution System - Comprehensive Analysis

## Overview

The join resolution system in Slice is responsible for:
1. Discovering relationships between tables (from `relations()` method)
2. Finding the shortest join path between required tables using BFS
3. Building a join graph that connects all needed tables
4. Applying joins to the Laravel query builder

Located in: `/home/user/laravel-analytics-builder/src/Engine/JoinResolver.php`

---

## Architecture Overview

```
Table Definition
├── relations() → array<string, Relation>
│   ├── BelongsTo relation
│   ├── HasMany relation
│   ├── BelongsToMany relation (defined but not fully implemented)
│   └── CrossJoin relation (for cross-domain joins)
│
↓
JoinResolver.buildJoinGraph()
├── Connects all required tables
├── Uses BFS to find shortest paths
└── Returns array of join specifications
    
↓
QueryBuilder (uses JoinResolver)
├── buildDatabasePlan() - for database joins
├── buildSoftwareJoinPlan() - for cross-driver joins
└── buildWithCTEs() - for computed metrics with CTEs
```

---

## Part 1: JoinResolver Core - Building the Join Graph

### Class Structure

**File**: `/home/user/laravel-analytics-builder/src/Engine/JoinResolver.php`

The JoinResolver has three main public methods:

```php
class JoinResolver {
    public function findJoinPath(Table $from, Table $to, array $allTables): ?array
    public function applyJoins(QueryAdapter $query, array $joinPath): QueryAdapter
    public function buildJoinGraph(array $tables): array
}
```

### Method 1: buildJoinGraph() - Entry Point

**Purpose**: Connect multiple tables with minimum joins

**Algorithm**: Greedy approach with BFS sub-component
- Start with the first table as "connected"
- Iteratively connect remaining tables
- For each unconnected table, find a path from ANY connected table to the target
- Avoid duplicate joins using a key-based deduplication strategy

**Code Flow**:

```php
public function buildJoinGraph(array $tables): array
{
    // Line 99-100: Early exit for single table
    if (count($tables) <= 1) {
        return [];
    }

    $allJoins = [];
    $connectedTables = [$tables[0]->table()];  // Start with first table

    // Line 107-128: Iteratively connect remaining tables
    for ($i = 1; $i < count($tables); $i++) {
        $targetTable = $tables[$i];

        // Try to find a path from ANY connected table to the target
        foreach ($tables as $sourceTable) {
            if (in_array($sourceTable->table(), $connectedTables) 
                && $sourceTable->table() !== $targetTable->table()) {
                
                $path = $this->findJoinPath($sourceTable, $targetTable, $tables);

                if ($path !== null) {
                    // Add all joins in this path (with deduplication)
                    foreach ($path as $join) {
                        $joinKey = $join['from'].'->'.$join['to'];
                        if (! isset($allJoins[$joinKey])) {
                            $allJoins[$joinKey] = $join;
                        }
                    }

                    $connectedTables[] = $targetTable->table();
                    break;  // Move to next target
                }
            }
        }
    }

    return array_values($allJoins);
}
```

**Return Value**: Array of join specifications

```php
[
    [
        'from' => 'orders',
        'to' => 'customers',
        'relation' => BelongsTo instance
    ],
    [
        'from' => 'orders',
        'to' => 'order_items',
        'relation' => HasMany instance
    ]
]
```

**Key Characteristics**:
- O(n²) worst case complexity for n tables
- Attempts to reuse already-connected tables as sources
- Deduplicates joins by relationship direction (orders→customers counted once)
- Does NOT guarantee shortest total joins across all tables (greedy, not optimal)

---

## Part 2: BFS Algorithm - Finding Shortest Path Between Two Tables

### Method 2: findJoinPath() - BFS Core

**Purpose**: Find the shortest join path from one table to another

**Algorithm**: Standard breadth-first search (BFS)

```php
public function findJoinPath(Table $from, Table $to, array $allTables): ?array
{
    // Line 20-21: Same table - no path needed
    if ($from->table() === $to->table()) {
        return [];
    }

    // Line 24-26: Initialize BFS queue and visited tracker
    $queue = [[$from, []]];
    $visited = [$from->table() => true];

    // Line 28-53: BFS main loop
    while (! empty($queue)) {
        [$currentTable, $path] = array_shift($queue);  // Dequeue

        // Explore all relations from current table
        foreach ($currentTable->relations() as $relationName => $relation) {
            $relatedTableClass = $relation->table();
            $relatedTable = new $relatedTableClass;
            $relatedTableName = $relatedTable->table();

            // Skip if already visited
            if (isset($visited[$relatedTableName])) {
                continue;
            }

            // Build new path including this relation
            $newPath = array_merge($path, [[
                'from' => $currentTable->table(),
                'to' => $relatedTableName,
                'relation' => $relation,
            ]]);

            // Check if we reached the target
            if ($relatedTableName === $to->table()) {
                return $newPath;  // Success! Return path immediately
            }

            // Mark visited and enqueue for exploration
            $visited[$relatedTableName] = true;
            $queue[] = [$relatedTable, $newPath];
        }
    }

    return null;  // No path exists
}
```

### BFS Detailed Walkthrough

**Example**: Find path from `order_items` to `customers`

```
Initial State:
Queue: [(OrderItemsTable, [])]
Visited: {order_items: true}

Step 1: Process order_items
├─ Relation 'order' → OrdersTable
│  └─ Not visited! Add to queue
│  └─ Path so far: [order_items→orders]
│  └─ Is this 'customers'? No
│
└─ Relation 'product' → ProductsTable
   └─ Not visited! Add to queue
   └─ Path so far: [order_items→products]
   └─ Is this 'customers'? No

Queue: [(OrdersTable, [order_items→orders]), 
        (ProductsTable, [order_items→products])]
Visited: {order_items: true, orders: true, products: true}

Step 2: Process orders
├─ Relation 'customer' → CustomersTable
│  └─ Not visited! Add to queue
│  └─ Path so far: [order_items→orders, orders→customers]
│  └─ Is this 'customers'? YES! RETURN PATH
```

**Result**: `[{from: order_items, to: orders, relation: BelongsTo}, {from: orders, to: customers, relation: BelongsTo}]`

### BFS Complexity Analysis

| Aspect | Details |
|--------|---------|
| **Time** | O(V + E) where V = tables, E = relations |
| **Space** | O(V) for queue + O(V) for visited tracking |
| **Path Guarantee** | Finds SHORTEST path (fewest hops) |
| **Termination** | Stops immediately when target found |
| **No Cycles** | Visited tracking prevents infinite loops |

---

## Part 3: Relation Types - How Different Relations Work

### Base Relation Class

**File**: `/home/user/laravel-analytics-builder/src/Tables/Relation.php`

```php
abstract class Relation
{
    public function __construct(
        protected string $table,
    ) {}

    public function table(): string
    {
        return $this->table;
    }
}
```

All relation classes extend this and add their own key information.

---

### Relation Type 1: BelongsTo

**File**: `/home/user/laravel-analytics-builder/src/Tables/BelongsTo.php`

**Purpose**: Parent/owner relationship (foreign key on child table points to parent)

**Database Structure**:
```
orders table:              customers table:
├─ id (PK)                ├─ id (PK)
├─ customer_id (FK)  ────→├─ name
└─ total                   └─ email
```

**Class Implementation**:
```php
class BelongsTo extends Relation
{
    public function __construct(
        protected string $table,           // 'Workbench\App\Analytics\Customers\CustomersTable'
        protected string $foreignKey,      // 'customer_id' (column in orders table)
        protected string $ownerKey = 'id', // 'id' (column in customers table)
    ) {
        parent::__construct($table);
    }

    public function foreignKey(): string { return $this->foreignKey; }
    public function ownerKey(): string { return $this->ownerKey; }
}
```

**Definition in Table**:
```php
class OrdersTable extends Table {
    public function relations(): array {
        return [
            'customer' => $this->belongsTo(
                CustomersTable::class,
                'customer_id'  // Foreign key in orders table
                // 'id' assumed as owner key in customers table
            ),
        ];
    }
}
```

**SQL Generated** (in applyJoins):
```sql
SELECT ... FROM orders
JOIN customers ON orders.customer_id = customers.id
```

**Join Logic in applyJoins** (Line 70-76):
```php
if ($relation instanceof BelongsTo) {
    $query->join(
        $toTable,
        "{$fromTable}.{$relation->foreignKey()}",      // orders.customer_id
        '=',
        "{$toTable}.{$relation->ownerKey()}"          // customers.id
    );
}
```

---

### Relation Type 2: HasMany

**File**: `/home/user/laravel-analytics-builder/src/Tables/HasMany.php`

**Purpose**: Parent with many children (parent table has local key, child table has foreign key)

**Database Structure**:
```
orders table:          order_items table:
├─ id (PK)  ←────────├─ order_id (FK)
├─ total              ├─ product_id (FK)
└─ status             ├─ quantity
                      └─ price
```

**Class Implementation**:
```php
class HasMany extends Relation
{
    public function __construct(
        protected string $table,              // 'Workbench\App\Analytics\OrderItems\OrderItemsTable'
        protected string $foreignKey,         // 'order_id' (column in order_items table)
        protected string $localKey = 'id',    // 'id' (column in orders table)
    ) {
        parent::__construct($table);
    }

    public function foreignKey(): string { return $this->foreignKey; }
    public function localKey(): string { return $this->localKey; }
}
```

**Definition in Table**:
```php
class OrdersTable extends Table {
    public function relations(): array {
        return [
            'items' => $this->hasMany(
                OrderItemsTable::class,
                'order_id'  // Foreign key in order_items table
                // 'id' assumed as local key in orders table
            ),
        ];
    }
}
```

**SQL Generated** (in applyJoins):
```sql
SELECT ... FROM orders
JOIN order_items ON orders.id = order_items.order_id
```

**Join Logic in applyJoins** (Line 77-83):
```php
} elseif ($relation instanceof HasMany) {
    $query->join(
        $toTable,
        "{$fromTable}.{$relation->localKey()}",       // orders.id
        '=',
        "{$toTable}.{$relation->foreignKey()}"        // order_items.order_id
    );
}
```

**Key Difference from BelongsTo**:
- BelongsTo: Foreign key is on the "from" table
- HasMany: Foreign key is on the "to" table (reverse relationship)

---

### Relation Type 3: BelongsToMany

**File**: `/home/user/laravel-analytics-builder/src/Tables/BelongsToMany.php`

**Purpose**: Many-to-many relationship through pivot table

**Database Structure**:
```
students table:       enrollments table:      courses table:
├─ id (PK)  ←────────├─ student_id (FK)      ├─ id (PK)
├─ name              ├─ course_id (FK)  ────→├─ title
└─ email             └─ grade                 └─ credits
```

**Class Implementation**:
```php
class BelongsToMany extends Relation
{
    public function __construct(
        protected string $table,           // Target table class
        protected string $pivotTable,      // 'enrollments'
        protected string $foreignKey,      // 'student_id' (in pivot)
        protected string $relatedKey,      // 'course_id' (in pivot)
    ) {
        parent::__construct($table);
    }

    public function pivotTable(): string { return $this->pivotTable; }
    public function foreignKey(): string { return $this->foreignKey; }
    public function relatedKey(): string { return $this->relatedKey; }
}
```

**Definition in Table**:
```php
class StudentsTable extends Table {
    public function relations(): array {
        return [
            'courses' => $this->belongsToMany(
                CoursesTable::class,
                'enrollments',   // Pivot table
                'student_id',    // FK to students
                'course_id'      // FK to courses
            ),
        ];
    }
}
```

**Current Status**: 
**IMPORTANT - NOT IMPLEMENTED IN applyJoins()**

Look at JoinResolver.php lines 77-85:
```php
} elseif ($relation instanceof HasMany) {
    // ...
}
// Add support for other relation types as needed
```

The comment indicates BelongsToMany is not yet implemented!

**What's Missing**:
1. No join logic in `applyJoins()` method
2. Would require TWO JOINs: to pivot table, then to related table
3. SQL should be:
```sql
SELECT ... FROM students
JOIN enrollments ON students.id = enrollments.student_id
JOIN courses ON enrollments.course_id = courses.id
```

---

### Relation Type 4: CrossJoin

**File**: `/home/user/laravel-analytics-builder/src/Tables/CrossJoin.php`

**Purpose**: Explicit cross-domain joins for tables without FK relationships (e.g., joining Orders and AdSpend on date)

**Use Case**: Marketing analytics where Orders and AdSpend have no direct relationship but should be joined on time

**Class Implementation**:
```php
class CrossJoin extends Relation
{
    public function __construct(
        protected string $table,           // Target table
        protected string $leftKey,         // Column in source table
        protected string $rightKey,        // Column in target table
        protected ?string $condition = null, // Optional: custom WHERE condition
    ) {
        parent::__construct($table);
    }

    public function leftKey(): string { return $this->leftKey; }
    public function rightKey(): string { return $this->rightKey; }
    public function condition(): ?string { return $this->condition; }
}
```

**Example Definition**:
```php
class OrdersTable extends Table {
    public function crossJoins(): array {
        return [
            'ad_spend' => $this->crossJoin(
                AdSpendTable::class,
                'DATE(orders.created_at)',
                'ad_spend.date'
            ),
        ];
    }
}
```

**Current Status**:
**ALSO NOT IMPLEMENTED IN JoinResolver**

- Defined in Table base class but not processed by JoinResolver
- Would require special handling because it's not a standard relation
- Stored in separate `crossJoins()` method, not `relations()`

---

## Part 4: How Joins Are Applied to Laravel Query Builder

### Method 3: applyJoins() - Executing the Join Graph

**File**: `/home/user/laravel-analytics-builder/src/Engine/JoinResolver.php` (Lines 63-89)

**Purpose**: Take the join graph and apply actual SQL JOINs to the query builder

```php
public function applyJoins(QueryAdapter $query, array $joinPath): QueryAdapter
{
    foreach ($joinPath as $join) {
        $relation = $join['relation'];
        $fromTable = $join['from'];
        $toTable = $join['to'];

        if ($relation instanceof BelongsTo) {
            $query->join(
                $toTable,
                "{$fromTable}.{$relation->foreignKey()}",
                '=',
                "{$toTable}.{$relation->ownerKey()}"
            );
        } elseif ($relation instanceof HasMany) {
            $query->join(
                $toTable,
                "{$fromTable}.{$relation->localKey()}",
                '=',
                "{$toTable}.{$relation->foreignKey()}"
            );
        }
        // Add support for other relation types as needed
    }

    return $query;
}
```

### QueryAdapter Interface

The `QueryAdapter` abstraction allows Slice to work with different database query builders:

```php
interface QueryAdapter {
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second
    ): self;
    
    public function selectRaw(string $expression): self;
    public function groupBy(string $column): self;
    public function where(string $column, string $operator, $value): self;
    // ... other methods
}
```

**Laravel Implementation**: `/home/user/laravel-analytics-builder/src/Engine/Drivers/LaravelQueryAdapter.php`

Maps to Illuminate\Database\Query\Builder:
```php
public function join($table, $first, $operator, $second): self
{
    $this->builder->join($table, $first, $operator, $second);
    return $this;
}
```

### Integration in QueryBuilder

The JoinResolver is used in two key places in QueryBuilder:

**1. Database Plan** (Lines 81-102 in QueryBuilder.php):
```php
protected function buildDatabasePlan(array $tables, array $normalizedMetrics, array $dimensions): DatabaseQueryPlan
{
    $primaryTable = $tables[0];
    $query = $this->driver->createQuery($primaryTable->table());

    if (count($tables) > 1) {
        $joinPath = $this->joinResolver->buildJoinGraph($tables);
        $query = $this->joinResolver->applyJoins($query, $joinPath);  // Apply joins
    }

    $this->addMetricSelects($query, $normalizedMetrics);
    // ... rest of query building
}
```

**2. Software Join Plan** (Lines 109-164):
```php
protected function buildSoftwareJoinPlan(array $tables, array $normalizedMetrics, array $dimensions): SoftwareJoinQueryPlan
{
    $primaryTable = $tables[0];
    $primaryTableName = $primaryTable->table();

    $joinGraph = $this->joinResolver->buildJoinGraph($tables);  // Get join graph
    
    // ... build software join relations and execute separate queries per table
}
```

**3. CTE Plan** (Lines 606-631):
```php
protected function buildBaseAggregationCTE(array $tables, array $metrics, array $dimensions): QueryAdapter
{
    // ... same pattern as database plan
    
    if (count($tables) > 1) {
        $joinPath = $this->joinResolver->buildJoinGraph($tables);
        $query = $this->joinResolver->applyJoins($query, $joinPath);
    }
}
```

---

## Part 5: Current Limitations

### 1. BelongsToMany Not Implemented

**Status**: Relation class exists but is never handled in `applyJoins()`

**Impact**: Cannot join tables through pivot tables

**Code Location**: Line 85 comment says "Add support for other relation types as needed"

**Fix Required**:
```php
} elseif ($relation instanceof BelongsToMany) {
    // Join through pivot table
    $pivotTable = $relation->pivotTable();
    $query->join($pivotTable, ...);
    $query->join($relation->table(), ...);
}
```

### 2. CrossJoin Not Integrated into JoinResolver

**Status**: Relation class exists but never used by BFS

**Issues**:
- Defined in `Table::crossJoins()` but not merged with `relations()`
- BFS algorithm doesn't explore CrossJoin paths
- No join generation in `applyJoins()`

**Impact**: Cannot join unrelated tables (Orders ↔ AdSpend on date)

**Fix Required**:
1. Merge `crossJoins()` results into `relations()` discovery
2. Handle CrossJoin in `applyJoins()`:
```php
} elseif ($relation instanceof CrossJoin) {
    $query->join($relation->table(), $relation->leftKey(), '=', $relation->rightKey());
}
```

### 3. Greedy Graph Building Not Optimal

**Status**: `buildJoinGraph()` uses greedy approach

**Issue**: Uses first connected table found; doesn't optimize total joins

**Example**: With tables A, B, C, D:
```
Greedy might find: A→B, B→C, C→D (3 joins)
Optimal might be: A→B, A→C, A→D (also 3, but different)
```

**Current Code** (Line 112):
```php
foreach ($tables as $sourceTable) {
    if (in_array($sourceTable->table(), $connectedTables) 
        && $sourceTable->table() !== $targetTable->table()) {
        $path = $this->findJoinPath($sourceTable, $targetTable, $tables);
        if ($path !== null) {
            // Use first path found (greedy)
            break;  // Don't try other sources
        }
    }
}
```

**Impact**: Usually negligible since most schemas are well-connected, but theoretically suboptimal

### 4. Circular Join Prevention

**Status**: No explicit circular join prevention in BFS visited tracking

**Why Not an Issue**: Table names are used as visited keys (not relation names), so cycles in the graph are prevented

**Example**: If A→B, B→A, start at A:
- Visit A, explore B
- Visit B, encounter A again - skip (already visited)

### 5. Join Type Assumptions

**Limitations**:
- Always uses INNER JOIN (no LEFT/RIGHT JOIN option)
- Assumes join direction is always source→target
- No support for conditional joins (NATURAL JOIN, ON conditions with multiple clauses)

---

## Part 6: Real-World Example

### Database Schema
```
customers table:
├─ id (PK)
├─ name
└─ country

orders table:
├─ id (PK)
├─ customer_id (FK→customers.id)
├─ total
└─ created_at

order_items table:
├─ id (PK)
├─ order_id (FK→orders.id)
├─ product_id (FK→products.id)
└─ price

products table:
├─ id (PK)
├─ name
└─ sku
```

### Table Definitions

```php
class OrdersTable extends Table {
    protected string $table = 'orders';
    
    public function relations(): array {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id'),
        ];
    }
}

class OrderItemsTable extends Table {
    protected string $table = 'order_items';
    
    public function relations(): array {
        return [
            'order' => $this->belongsTo(OrdersTable::class, 'order_id'),
            'product' => $this->belongsTo(ProductsTable::class, 'product_id'),
        ];
    }
}
```

### Query Execution

```php
Slice::query()
    ->metrics([
        Sum::make('orders.total'),
        Sum::make('order_items.price'),
    ])
    ->dimensions([Dimension::make('status')])
    ->get();
```

### Join Resolution Flow

**Step 1: buildJoinGraph([OrdersTable, OrderItemsTable])**

```
connectedTables = ['orders']
allJoins = []
iteration i=1 (target: order_items)

  Loop through all tables:
    sourceTable = OrdersTable (in connectedTables)
    targetTable = OrderItemsTable (not in connectedTables)
    
    findJoinPath(OrdersTable, OrderItemsTable):
      Queue: [(OrdersTable, [])]
      Visited: {orders: true}
      
      Process OrdersTable:
        Relation 'customer' → CustomersTable
          Not visited, not target, enqueue
        Relation 'items' → OrderItemsTable  
          Not visited, IS TARGET!
          Return [{from: orders, to: order_items, relation: HasMany}]
    
    path found! Add to allJoins:
      orders->order_items: {from: orders, to: order_items, relation: HasMany}
    
    connectedTables = ['orders', 'order_items']
    break

return [{from: 'orders', to: 'order_items', relation: HasMany}]
```

**Step 2: applyJoins(query, joinPath)**

```
foreach [{from: 'orders', to: 'order_items', relation: HasMany}]:
    relation instanceof HasMany → true
    query.join(
        'order_items',                    // toTable
        'orders.id',                      // localKey
        '=',
        'order_items.order_id'            // foreignKey
    )

Resulting SQL:
  FROM orders
  JOIN order_items ON orders.id = order_items.order_id
```

**Step 3: Final SQL**

```sql
SELECT
    SUM(orders.total) as orders_total,
    SUM(order_items.price) as order_items_price,
    orders.status
FROM orders
JOIN order_items ON orders.id = order_items.order_id
GROUP BY orders.status
ORDER BY orders.status
```

---

## Summary Table: Relation Handling

| Aspect | BelongsTo | HasMany | BelongsToMany | CrossJoin |
|--------|-----------|---------|---------------|-----------|
| **Status** | ✅ Implemented | ✅ Implemented | ❌ Stub | ❌ Not integrated |
| **FK Position** | On source table | On target table | In pivot table | Custom |
| **BFS Discovery** | ✅ Via relations() | ✅ Via relations() | ✅ Via relations() | ❌ Via crossJoins() |
| **applyJoins** | ✅ Join code | ✅ Join code | ❌ Missing | ❌ Missing |
| **SQL Joins** | 1 JOIN | 1 JOIN | 2 JOINs | 1 JOIN |
| **Example** | Order→Customer | Order→Items | Student→Courses | Orders→AdSpend |

---

## Recommendations for Improvements

### High Priority

1. **Implement BelongsToMany Support**
   - Add case in `applyJoins()` to handle pivot tables
   - Use two sequential joins

2. **Implement CrossJoin Integration**
   - Merge `crossJoins()` into relation discovery
   - Update BFS to explore CrossJoin paths
   - Add handling in `applyJoins()`

### Medium Priority

3. **Add LEFT/RIGHT JOIN Support**
   - Extend Relation classes with join type property
   - Allow optional joins (e.g., for dimensional tables)

4. **Optimize Graph Building**
   - Consider using Dijkstra's algorithm for true shortest path
   - Current greedy approach works but is suboptimal

### Low Priority

5. **Add Circular Join Detection**
   - Explicit check for cycles in relation graph
   - Warn users of potentially problematic schemas

6. **Support Complex Join Conditions**
   - Allow multiple ON conditions
   - Support JOIN ... ON ... AND ... syntax

---

## Testing the Join System

Key test file: `/home/user/laravel-analytics-builder/tests/Unit/QueryTest.php`

**Test Case: software-join fallback matches database join output** (Lines 87-158)

Tests that when database doesn't support joins (NoJoinLaravelDriver), results match database joins:
- Creates orders with items
- Queries SUM(orders.total), SUM(order_items.price) grouped by status
- Compares database join result vs software join fallback
- Verifies they produce identical results

This ensures that even when joins can't be done in SQL, the query engine can fall back to in-memory joins.

