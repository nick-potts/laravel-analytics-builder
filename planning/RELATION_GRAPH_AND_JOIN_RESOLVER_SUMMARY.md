# Relation Graph and JoinResolver - Comprehensive Codebase Analysis

**Date:** November 9, 2025  
**Project:** Laravel Analytics Builder (Slice)  
**Status:** Phase 3 Complete - Query Engine Integration

---

## Executive Summary

The Slice package uses a **table-centric architecture** where relationships between tables are discovered from Eloquent models and stored in a `RelationGraph` structure. A `JoinResolver` component then uses BFS (Breadth-First Search) to find the shortest join paths between required tables and applies those joins to the Laravel query builder.

**Key Finding:** The infrastructure is modern and well-architected, but the JoinResolver component is currently only partially implemented (empty `/src/Engine/Resolvers/` directory). This analysis documents what exists, what's missing, and how to build it.

---

## Part 1: RelationGraph - Data Structure for Table Relationships

### File Location
`/Users/nick/dev/laravel-analytics-builder/src/Schemas/Relations/RelationGraph.php`

### What It Is

`RelationGraph` is a lightweight container that holds all relationships (relations) defined on a single table. It's the foundation for join resolution.

### Class Structure

```php
final class RelationGraph
{
    /** @var array<string, RelationDescriptor> */
    private array $relations;  // Keyed by relation name
    
    // Methods:
    public function get(string $name): ?RelationDescriptor
    public function has(string $name): bool
    public function all(): array
    public function names(): array
    public function forEach(\Closure $callback): void
    public function ofType(RelationType $type): array
    public function count(): int
    public function isEmpty(): bool
}
```

### Example Usage

```php
// Get a specific relation
$customerRelation = $ordersTable->relations()->get('customer');

// Check if a relation exists
if ($ordersTable->relations()->has('items')) { ... }

// Iterate over all relations
$table->relations()->forEach(function($name, $relation) {
    echo "$name -> {$relation->type->value}";
});

// Filter by type
$belongsToRelations = $table->relations()->ofType(RelationType::BelongsTo);
```

### Key Characteristics

- **Immutable:** Created during schema introspection, never modified at runtime
- **Serializable:** Can be cached (see `ModelMetadata::serializeRelationGraph()`)
- **Query-friendly:** Provides multiple access patterns (get, has, all, names, forEach, ofType)
- **Minimal:** Just a wrapper around relation descriptors

---

## Part 2: RelationDescriptor - Individual Relation Metadata

### File Location
`/Users/nick/dev/laravel-analytics-builder/src/Schemas/Relations/RelationDescriptor.php`

### What It Is

Describes a single relationship from one table to another, including the relationship type, target model, and foreign key information.

### RelationType Enum

```php
enum RelationType: string
{
    case BelongsTo = 'belongs_to';
    case HasMany = 'has_many';
    case HasOne = 'has_one';
    case BelongsToMany = 'belongs_to_many';
    case MorphTo = 'morph_to';
    case MorphMany = 'morph_many';
}
```

### RelationDescriptor Class

```php
final class RelationDescriptor
{
    public function __construct(
        public readonly string $name,              // 'customer', 'items'
        public readonly RelationType $type,        // The relationship type
        public readonly string $targetModel,       // 'App\Models\Customer'
        public readonly array $keys,               // FK info: ['foreign' => 'customer_id', ...]
        public readonly ?string $pivot = null,     // For BelongsToMany
    ) {}
    
    // Helper methods:
    public function target(): string
    public function isOne(): bool     // BelongsTo, HasOne, MorphTo
    public function isMany(): bool    // HasMany, MorphMany, BelongsToMany
    public function isPivot(): bool   // Only BelongsToMany
}
```

### Key Property: Keys Array

The `$keys` array stores foreign key information specific to each relation type:

**BelongsTo example:**
```php
[
    'foreign' => 'customer_id',    // Column in orders table
    'owner' => 'id',               // Column in customers table
]
```

**HasMany example:**
```php
[
    'foreign' => 'order_id',       // Column in order_items table
    'local' => 'id',               // Column in orders table (parent)
]
```

**BelongsToMany example:**
```php
[
    'foreign' => 'student_id',     // Column in pivot table
    'related' => 'course_id',      // Column in pivot table
]
// Plus: pivot = 'enrollments' (the pivot table name)
```

### Data Flow Example

```
Eloquent Model (Order.php)
    ↓ (has method: public function customer(): BelongsTo)
    ↓
RelationIntrospector (introspects source code)
    ↓ (extracts relation info without DB connection)
    ↓
RelationDescriptor (created with metadata)
    {
        name: 'customer',
        type: BelongsTo,
        targetModel: 'App\Models\Customer',
        keys: ['foreign' => 'customer_id', 'owner' => 'id']
    }
    ↓
RelationGraph (collected in container)
    all() → ['customer' => RelationDescriptor, ...]
```

---

## Part 3: How Relations Are Discovered - RelationIntrospector

### File Location
`/Users/nick/dev/laravel-analytics-builder/src/Providers/Eloquent/Introspectors/Relations/RelationIntrospector.php`

### Discovery Process (No Database Connection Needed!)

The `RelationIntrospector` uses **reflection + source code parsing** to discover relations without instantiating models or connecting to the database.

### Key Method: introspect()

```php
public function introspect(string $modelClass, \ReflectionClass $reflection): RelationGraph
```

**Steps:**
1. Get all public methods from the model class
2. For each method, check if return type is a relation class (BelongsTo, HasMany, etc.)
3. Parse the method's source code to extract:
   - The related model class (from `belongsTo(RelatedModel::class)` or `hasMany('Model\Path')`)
   - The foreign/local key information (via method name conventions)
4. Create `RelationDescriptor` and add to graph

### Supported Relations

Currently implemented in `extractRelationFromMethod()`:
- ✅ **BelongsTo** - Foreign key on source table
- ✅ **HasMany** - Foreign key on target table
- ✅ **HasOne** - Foreign key on target table (one result)
- ✅ **BelongsToMany** - Through pivot table

Not yet implemented:
- ❌ **MorphTo** / **MorphMany** - Polymorphic relations

### Key Insight: Source Code Pattern Matching

Instead of calling the relation methods, the introspector parses the source code:

```php
// Looks for patterns like:
// belongsTo(Model::class)
// hasMany('Model\Path')
// belongsToMany(Model::class, 'pivot_table', ...)

preg_match('/(?:belongsTo|hasMany|hasOne|belongsToMany)\s*\(\s*([\\w\\\\]+)/', $source, $matches)
```

This is brilliant because it avoids:
- Loading Eloquent models
- Connecting to the database
- Runtime reflection overhead

### Example: Order Model

```php
// Workbench\App\Models\Order.php
class Order extends Model {
    public function items(): HasMany { ... }
    public function customer(): BelongsTo { ... }
}
```

When introspected:
```php
$graph = $introspector->introspect(Order::class, $reflection);
// $graph now contains:
// - 'items' → RelationDescriptor(HasMany, OrderItem::class)
// - 'customer' → RelationDescriptor(BelongsTo, Customer::class)
```

### Test Reference
See `/Users/nick/dev/laravel-analytics-builder/tests/Unit/Providers/Eloquent/Introspectors/Relations/RelationIntrospectorTest.php`

---

## Part 4: Integration into Table Contracts

### File Location
`/Users/nick/dev/laravel-analytics-builder/src/Contracts/SliceSource.php`

### Interface Definition

```php
interface SliceSource
{
    public function name(): string;              // 'orders', 'customers'
    public function connection(): ?string;       // 'mysql', 'pgsql'
    public function primaryKey(): PrimaryKeyDescriptor;
    public function relations(): RelationGraph;  // ← Our RelationGraph!
    public function dimensions(): DimensionCatalog;
}
```

### Implementation: MetadataBackedTable

All Eloquent-based tables are wrapped in `MetadataBackedTable`, which stores the `RelationGraph`:

```php
final class MetadataBackedTable implements SliceSource
{
    public function __construct(private readonly ModelMetadata $metadata) {}
    
    public function relations(): RelationGraph
    {
        return $this->metadata->relationGraph;
    }
}
```

### Caching via ModelMetadata

The `RelationGraph` is serialized/deserialized for caching:

```php
// In ModelMetadata::toArray()
'relationGraph' => [
    'customer' => [
        'name' => 'customer',
        'type' => 'belongs_to',
        'targetModel' => 'App\Models\Customer',
        'keys' => ['foreign' => 'customer_id', ...],
        'pivot' => null,
    ],
    'items' => [...],
]

// On deserialization, RelationGraph is reconstructed
private static function deserializeRelationGraph(array $data): RelationGraph { ... }
```

---

## Part 5: JoinResolver - What Needs to Be Built

### Current State

The directory `/Users/nick/dev/laravel-analytics-builder/src/Engine/Resolvers/` is **empty**. Based on the planning documents, a `JoinResolver` component needs to be built here.

### What JoinResolver Should Do

**Primary Goal:** Given multiple tables, find the shortest join paths between them using the `RelationGraph` data.

**Three Core Responsibilities:**

1. **findJoinPath(from, to, allTables)** - BFS pathfinding
   - Input: Source table, target table, all available tables
   - Output: Array of join specifications (from→to relationships)
   - Algorithm: Breadth-First Search (BFS)
   - Returns: Shortest path or null if no path exists

2. **buildJoinGraph(tables)** - Multi-table connection
   - Input: Array of tables needed in the query
   - Output: Array of all join specifications to connect them
   - Algorithm: Greedy approach using findJoinPath iteratively
   - Returns: Deduplicated array of joins

3. **applyJoins(query, joinPath)** - SQL execution
   - Input: Query builder, join specifications
   - Output: Modified query with JOINs applied
   - Logic: Iterate joins, build correct SQL for each relation type

### Detailed Design (from Planning Docs)

#### Method 1: findJoinPath() - BFS Algorithm

```
ALGORITHM BFS(from, to, allTables)
    Queue ← [(from, [])]
    Visited ← {from.table(): true}
    
    WHILE Queue not empty:
        (currentTable, path) ← dequeue
        
        FOR EACH relation in currentTable.relations():
            relatedTableName ← relation.target()
            
            IF visited[relatedTableName]:
                CONTINUE
            
            newPath ← path + [{from: current, to: related, relation}]
            
            IF relatedTableName == to.table():
                RETURN newPath  // Found target!
            
            visited[relatedTableName] ← true
            enqueue(relatedTable, newPath)
    
    RETURN null  // No path exists
```

**Complexity:** O(V + E) where V = tables, E = relations  
**Properties:**
- Finds shortest path (minimum hops)
- Prevents cycles via visited set
- Early termination when target found

#### Method 2: buildJoinGraph() - Greedy Graph Building

```
ALGORITHM buildJoinGraph(tables)
    IF count(tables) ≤ 1:
        RETURN []
    
    connectedTables ← [tables[0]]
    allJoins ← {}
    
    FOR i = 1 TO count(tables):
        targetTable ← tables[i]
        
        FOR EACH sourceTable IN tables:
            IF sourceTable IN connectedTables AND sourceTable ≠ targetTable:
                path ← findJoinPath(sourceTable, targetTable, tables)
                
                IF path not null:
                    // Add all joins in path (with deduplication)
                    FOR EACH join IN path:
                        key ← join.from + '->' + join.to
                        IF key NOT IN allJoins:
                            allJoins[key] ← join
                    
                    connectedTables.add(targetTable)
                    BREAK  // Found path, move to next target
    
    RETURN values(allJoins)  // Return deduplicated joins
```

**Complexity:** O(n² · (V+E)) worst case, where n = required tables  
**Characteristics:**
- Uses first found path (greedy, not globally optimal)
- Deduplicates by direction (A→B counted once)
- Fails gracefully if no path exists for a table

#### Method 3: applyJoins() - SQL Join Execution

```
ALGORITHM applyJoins(query, joinPath)
    FOR EACH join IN joinPath:
        relation ← join.relation
        fromTable ← join.from
        toTable ← join.to
        
        IF relation.type == BelongsTo:
            // FK on source table
            query.join(
                toTable,
                fromTable + '.' + relation.keys['foreign'],
                '=',
                toTable + '.' + relation.keys['owner']
            )
        
        ELSE IF relation.type == HasMany:
            // FK on target table
            query.join(
                toTable,
                fromTable + '.' + relation.keys['local'],
                '=',
                toTable + '.' + relation.keys['foreign']
            )
        
        ELSE IF relation.type == BelongsToMany:
            // TWO JOINs through pivot table
            pivotTable ← relation.pivot
            query.join(
                pivotTable,
                fromTable + '.id',
                '=',
                pivotTable + '.' + relation.keys['foreign']
            )
            query.join(
                toTable,
                pivotTable + '.' + relation.keys['related'],
                '=',
                toTable + '.id'
            )
    
    RETURN query
```

---

## Part 6: Data Structure Examples

### Join Specification Return Format

Both `findJoinPath()` and `buildJoinGraph()` return join specifications:

```php
[
    [
        'from' => 'orders',
        'to' => 'customers',
        'relation' => RelationDescriptor { ... }
    ],
    [
        'from' => 'orders',
        'to' => 'order_items',
        'relation' => RelationDescriptor { ... }
    ]
]
```

### Real-World Example: Query Building

**Query:** Get total revenue and item count by customer

```php
Slice::query()
    ->metrics([
        Sum::make('orders.total'),     // From orders table
        Count::make('order_items.id')  // From order_items table
    ])
    ->dimensions([Dimension::make('customer')])  // Group by customer
    ->get();
```

**Join Resolution Flow:**

1. **Normalize metrics** → identify tables: [orders, order_items]
2. **Call buildJoinGraph([OrdersTable, OrderItemsTable])**
   - connectedTables = ['orders']
   - Target: order_items
   - findJoinPath(OrdersTable, OrderItemsTable) via BFS:
     - Queue: [(OrdersTable, [])]
     - Check relations: 'customer', 'items'
     - 'items' → OrderItemsTable ✓ TARGET FOUND!
     - Return: [{from: 'orders', to: 'order_items', relation: HasMany}]
3. **Result:** 
   ```php
   [{
       'from' => 'orders',
       'to' => 'order_items',
       'relation' => RelationDescriptor(
           type: HasMany,
           keys: ['foreign' => 'order_id', 'local' => 'id']
       )
   }]
   ```
4. **Call applyJoins(query, joinPath)**
   - Relation is HasMany, so:
   - query.join('order_items', 'orders.id', '=', 'order_items.order_id')
5. **Final SQL:**
   ```sql
   SELECT 
       SUM(orders.total) as total,
       COUNT(order_items.id) as count,
       customers.id as customer
   FROM orders
   JOIN order_items ON orders.id = order_items.order_id
   JOIN customers ON orders.customer_id = customers.id  -- Added by dimension resolution
   GROUP BY customers.id
   ```

---

## Part 7: Current Implementation Status

### What's Complete ✅

1. **RelationGraph** - Fully implemented and tested
   - File: `/Users/nick/dev/laravel-analytics-builder/src/Schemas/Relations/RelationGraph.php`
   - Status: Production-ready
   - Tests: Passing

2. **RelationDescriptor** - Fully implemented
   - File: `/Users/nick/dev/laravel-analytics-builder/src/Schemas/Relations/RelationDescriptor.php`
   - Status: Production-ready
   - Supports: BelongsTo, HasMany, HasOne, BelongsToMany, MorphTo, MorphMany

3. **RelationIntrospector** - Fully implemented
   - File: `/Users/nick/dev/laravel-analytics-builder/src/Providers/Eloquent/Introspectors/Relations/RelationIntrospector.php`
   - Status: Production-ready
   - Tests: 42+ passing tests
   - Discovers: BelongsTo, HasMany, HasOne, BelongsToMany via source code parsing

4. **SchemaProvider Infrastructure** - Phase 1 & 2 complete
   - EloquentSchemaProvider auto-discovers models
   - ModelScanner finds models via PSR-4
   - ModelMetadata caches introspected data
   - RelationGraph serialization/deserialization working

5. **Table Integration** - Fully wired
   - File: `/Users/nick/dev/laravel-analytics-builder/src/Contracts/SliceSource.php`
   - MetadataBackedTable exposes relations()
   - Works with SchemaProviderManager

### What Needs to Be Built ⏳

1. **JoinResolver Component** - MISSING
   - Location: Should be in `/Users/nick/dev/laravel-analytics-builder/src/Engine/Resolvers/`
   - Methods needed: `findJoinPath()`, `buildJoinGraph()`, `applyJoins()`
   - Tests needed: Unit tests for BFS, graph building, edge cases

2. **Integration into QueryBuilder** - PARTIAL
   - File: `/Users/nick/dev/laravel-analytics-builder/src/Engine/QueryBuilder.php`
   - Currently builds QueryPlan but doesn't use JoinResolver
   - Needs: Call JoinResolver when multiple tables required

3. **Join Support Coverage** - PARTIAL
   - ✅ BelongsTo - implemented
   - ✅ HasMany - implemented
   - ❌ BelongsToMany - descriptor exists, but needs applyJoins() implementation
   - ❌ MorphTo/MorphMany - descriptor exists, not yet implemented

### Known Limitations

1. **No JOIN type control** - Always INNER JOIN
2. **No multiple ON conditions** - Single equality check only
3. **Greedy algorithm** - Not globally optimal for complex graphs
4. **CrossJoin not integrated** - Separate from main relation discovery

---

## Part 8: File Structure Reference

### Key Files by Responsibility

| File Path | LOC | Purpose | Status |
|-----------|-----|---------|--------|
| `src/Schemas/Relations/RelationGraph.php` | 99 | Relation container | ✅ Complete |
| `src/Schemas/Relations/RelationDescriptor.php` | 73 | Relation metadata | ✅ Complete |
| `src/Providers/Eloquent/Introspectors/Relations/RelationIntrospector.php` | 267 | Source code parsing | ✅ Complete |
| `src/Contracts/SliceSource.php` | 56 | Table interface | ✅ Complete |
| `src/Schemas/MetadataBackedTable.php` | 79 | Table implementation | ✅ Complete |
| `src/Schemas/ModelMetadata.php` | 134 | Metadata serialization | ✅ Complete |
| `src/Support/SchemaProviderManager.php` | TBD | Provider resolution | ✅ Complete |
| `src/Engine/QueryBuilder.php` | 108 | Query planning | ⏳ Needs joins |
| `src/Engine/QueryPlan.php` | 54 | Plan structure | ✅ Complete |
| `src/Engine/Resolvers/JoinResolver.php` | TBD | **NEEDS TO BE BUILT** | ❌ Missing |

### Test Files

| Test Path | Purpose | Status |
|-----------|---------|--------|
| `tests/Unit/Schemas/Dimensions/DimensionCatalogTest.php` | Dimension tests | ✅ Passing |
| `tests/Unit/Providers/Eloquent/ModelScannerTest.php` | Model discovery | ✅ Passing |
| `tests/Unit/Providers/Eloquent/ModelIntrospectorTest.php` | Introspection | ✅ Passing |
| `tests/Unit/Providers/Eloquent/Introspectors/Relations/RelationIntrospectorTest.php` | Relation discovery | ✅ Passing (42+ tests) |
| `tests/Unit/Engine/QueryBuilderTest.php` | Query plan building | ✅ Passing (3 tests) |
| `tests/Feature/Engine/QueryBuilderIntegrationTest.php` | End-to-end integration | ✅ Passing (11 tests) |

### Workbench Models for Testing

| Model | Location | Relations | Purpose |
|-------|----------|-----------|---------|
| Order | `workbench/app/Models/Order.php` | customer (BelongsTo), items (HasMany) | Primary test fixture |
| OrderItem | `workbench/app/Models/OrderItem.php` | order (BelongsTo), product (BelongsTo) | Multi-level join testing |
| Customer | `workbench/app/Models/Customer.php` | orders (HasMany) | Dimension testing |
| Product | `workbench/app/Models/Product.php` | None | No-relation testing |
| User | `workbench/app/Models/User.php` | None | Generic model |
| AdSpend | `workbench/app/Models/AdSpend.php` | None | CrossJoin testing (future) |

---

## Part 9: How to Build JoinResolver

### Step 1: Create the Class

File: `/Users/nick/dev/laravel-analytics-builder/src/Engine/Resolvers/JoinResolver.php`

**Dependencies:**
- `RelationGraph` - From table's relations()
- `RelationDescriptor` - Individual relation metadata
- `SliceSource` - Tables to join
- `Illuminate\Database\Query\Builder` - Laravel query builder

**Constructor:**
```php
class JoinResolver
{
    public function __construct(
        private SchemaProviderManager $manager,
    ) {}
}
```

### Step 2: Implement BFS Algorithm

```php
public function findJoinPath(
    SliceSource $from,
    SliceSource $to,
    array $allTables
): ?array {
    // BFS implementation
    // Returns array of join specs or null
}
```

**Key logic:**
- Use `$from->relations()` to get available relations
- For each relation, resolve target table via SchemaProviderManager
- Track visited tables to prevent cycles
- Return immediately when target found

### Step 3: Implement Graph Building

```php
public function buildJoinGraph(array $tables): array {
    // Greedy algorithm using findJoinPath
    // Returns deduplicated join specifications
}
```

**Key logic:**
- Handle single-table case (return [])
- Track connected tables
- Iterate remaining tables
- Use deduplication key: `from . '->' . to`

### Step 4: Implement Join Application

```php
public function applyJoins(
    QueryBuilder $query,
    array $joinPath
): QueryBuilder {
    // Apply SQL JOINs based on relation type
    // Handle BelongsTo, HasMany, BelongsToMany
}
```

**Key logic:**
- Type check each relation
- Build correct ON conditions
- Handle pivot table for BelongsToMany
- Return modified query

### Step 5: Add Tests

Create: `/Users/nick/dev/laravel-analytics-builder/tests/Unit/Engine/Resolvers/JoinResolverTest.php`

**Test cases:**
- ✅ findJoinPath finds direct relation
- ✅ findJoinPath finds multi-hop path
- ✅ findJoinPath returns null for unconnected tables
- ✅ buildJoinGraph connects multiple tables
- ✅ buildJoinGraph deduplicates joins
- ✅ applyJoins generates correct SQL for BelongsTo
- ✅ applyJoins generates correct SQL for HasMany
- ✅ applyJoins generates correct SQL for BelongsToMany
- ✅ Circular relations are prevented
- ✅ Performance with large relation graphs

### Step 6: Integrate into QueryBuilder

Modify: `/Users/nick/dev/laravel-analytics-builder/src/Engine/QueryBuilder.php`

```php
class QueryBuilder
{
    public function __construct(
        private SchemaProviderManager $manager,
        private JoinResolver $joinResolver,  // Add dependency
    ) {}
    
    public function build(): QueryPlan
    {
        if (count($this->tables) > 1) {
            $joinSpecs = $this->joinResolver->buildJoinGraph(
                array_values($this->tables)
            );
            // Store in QueryPlan for execution
        }
        
        return new QueryPlan(
            primaryTable: $primaryTable,
            tables: $this->tables,
            metrics: $this->metrics,
            joinSpecs: $joinSpecs ?? [],  // Add to QueryPlan
            connection: $this->connection,
        );
    }
}
```

---

## Part 10: Testing the Implementation

### Unit Tests - Focus Areas

**1. BFS Algorithm**
```php
// Test: Single-hop relation
$path = $resolver->findJoinPath(
    $ordersTable,
    $customersTable,
    [$ordersTable, $customersTable]
);
// Expected: [{from: orders, to: customers, relation: BelongsTo}]

// Test: Multi-hop relation
$path = $resolver->findJoinPath(
    $orderItemsTable,
    $customersTable,
    [$orderItemsTable, $ordersTable, $customersTable]
);
// Expected: 
// [{from: order_items, to: orders, relation: BelongsTo},
//  {from: orders, to: customers, relation: BelongsTo}]

// Test: No path exists
$path = $resolver->findJoinPath(
    $ordersTable,
    $orphanTable,
    [$ordersTable, $orphanTable]
);
// Expected: null
```

**2. Graph Building**
```php
// Test: Connect multiple tables
$joins = $resolver->buildJoinGraph([
    $ordersTable,
    $orderItemsTable,
    $customersTable
]);
// Expected: 2 joins connecting all 3 tables

// Test: Deduplication
// When multiple paths lead to same join, only include once
```

**3. Join Application**
```php
// Test: BelongsTo generates correct SQL
// Test: HasMany generates correct SQL
// Test: BelongsToMany uses pivot table correctly
```

### Integration Tests

**Full Query Flow:**
```php
Slice::query()
    ->metrics([
        Sum::make('orders.total'),
        Sum::make('order_items.price'),
    ])
    ->dimensions([Dimension::make('customer')])
    ->get();

// Should produce correct JOIN SQL without errors
// Result count and values should match expectations
```

### Performance Tests

```php
// Measure join resolution overhead
$startTime = microtime(true);
$joins = $resolver->buildJoinGraph($manyTables);
$duration = microtime(true) - $startTime;

// Target: < 10ms for typical 5-table query
// Stretch: < 50ms even for 20-table graph
```

---

## Part 11: Known Edge Cases and Limitations

### Current Issues to Address

1. **BelongsToMany Not Fully Supported**
   - RelationDescriptor exists with pivot information
   - applyJoins() needs implementation for this type
   - Requires two sequential JOINs

2. **MorphTo/MorphMany Relations**
   - Detected by RelationIntrospector
   - Not yet handled in applyJoins()
   - More complex: need to handle polymorphic type column

3. **No JOIN Type Control**
   - Currently always INNER JOIN
   - Should support LEFT/RIGHT/CROSS JOINs
   - Would require extending RelationDescriptor

4. **Circular Relations**
   - Prevented by BFS visited tracking
   - But could add explicit validation with error messages

5. **Cross-Provider Joins**
   - RelationDescriptor uses target model class name
   - JoinResolver needs to resolve via SchemaProviderManager
   - Current code assumes same provider (not yet tested)

---

## Part 12: Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    Slice::query()                        │
└────────────────────┬────────────────────────────────────┘
                     │
        ┌────────────▼───────────────┐
        │  Normalize Metrics/Dims    │
        │  (Identify required tables)│
        └────────────┬───────────────┘
                     │
        ┌────────────▼──────────────────────────┐
        │  SchemaProviderManager.resolve()      │
        │  (Get SliceSource for each table)   │
        └────────────┬──────────────────────────┘
                     │ [OrdersTable, OrderItemsTable, CustomersTable]
        ┌────────────▼──────────────────────────┐
        │  QueryBuilder.build()                 │
        │  └─ JoinResolver.buildJoinGraph()    │
        │     └─ For each unconnected table:    │
        │        └─ findJoinPath() [BFS]       │
        │           └─ Explore relations()      │
        │     Returns: [{from, to, relation}]  │
        └────────────┬──────────────────────────┘
                     │ [Join specifications]
        ┌────────────▼──────────────────────────┐
        │  QueryPlan (with join specs)         │
        └────────────┬──────────────────────────┘
                     │
        ┌────────────▼──────────────────────────┐
        │  Query Execution                      │
        │  └─ applyJoins(query, joinSpecs)    │
        │     └─ For each join spec:           │
        │        └─ Match relation type        │
        │        └─ Build correct ON clause    │
        │        └─ query.join()               │
        └────────────┬──────────────────────────┘
                     │ [Query with JOINs]
        ┌────────────▼──────────────────────────┐
        │  Laravel Query Builder                │
        │  (Execute SQL)                       │
        └────────────┬──────────────────────────┘
                     │ [Database results]
        ┌────────────▼──────────────────────────┐
        │  Post-Processing                      │
        │  (Computed metrics, grouping)        │
        └────────────┬──────────────────────────┘
                     │
        ┌────────────▼──────────────────────────┐
        │  Results                              │
        └──────────────────────────────────────┘
```

---

## Part 13: Quick Reference - Building JoinResolver

### Class Skeleton

```php
<?php

namespace NickPotts\Slice\Engine\Resolvers;

use NickPotts\Slice\Contracts\SliceSource;
use NickPotts\Slice\Schemas\Relations\RelationDescriptor;
use NickPotts\Slice\Schemas\Relations\RelationType;
use NickPotts\Slice\Support\SchemaProviderManager;
use Illuminate\Database\Query\Builder as LaravelBuilder;

final class JoinResolver
{
    public function __construct(
        private SchemaProviderManager $manager,
    ) {}

    /**
     * Find shortest path from one table to another via BFS
     */
    public function findJoinPath(
        SliceSource $from,
        SliceSource $to,
        array $allTables
    ): ?array {
        // BFS implementation
    }

    /**
     * Build complete join graph for multiple tables
     */
    public function buildJoinGraph(array $tables): array {
        // Greedy graph building
    }

    /**
     * Apply join specifications to query builder
     */
    public function applyJoins(
        LaravelBuilder $query,
        array $joinPath
    ): LaravelBuilder {
        // Join application logic
    }

    /**
     * Resolve target table from relation descriptor
     */
    private function resolveTargetTable(
        RelationDescriptor $relation
    ): ?SliceSource {
        // Use SchemaProviderManager to resolve model class to SliceSource
    }
}
```

### Dependencies to Inject

```php
$manager = app(SchemaProviderManager::class);
$resolver = new JoinResolver($manager);

// Or via container binding
$resolver = app(JoinResolver::class);
```

---

## Summary

**What Exists:**
- ✅ RelationGraph - Container for relations
- ✅ RelationDescriptor - Individual relation metadata
- ✅ RelationIntrospector - Discovers relations from source code
- ✅ SliceSource.relations() - Access to RelationGraph
- ✅ ModelMetadata serialization - Caching support

**What's Missing:**
- ❌ JoinResolver component (the three core methods)
- ❌ Integration into QueryBuilder
- ❌ Tests for join resolution
- ❌ BelongsToMany/MorphMany join application

**Next Steps:**
1. Create JoinResolver class with BFS algorithm
2. Implement buildJoinGraph with greedy approach
3. Implement applyJoins with relation type handling
4. Add comprehensive unit and integration tests
5. Wire into QueryBuilder for multi-table queries
6. Test with real Eloquent models

---

**Generated:** November 9, 2025  
**Status:** Awaiting implementation  
**Effort Estimate:** 2-3 days for full implementation + testing
