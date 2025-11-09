# Relation Graph & JoinResolver - Quick Start Guide

**File:** `/Users/nick/dev/laravel-analytics-builder/planning/RELATION_GRAPH_AND_JOIN_RESOLVER_SUMMARY.md` (comprehensive 1000+ line guide)

---

## TL;DR - What You Need to Know

### What Works (✅ Complete)

1. **RelationGraph** - Container that holds relations for a table
   - Location: `src/Schemas/Relations/RelationGraph.php`
   - Status: Production-ready
   - Provides: `get()`, `has()`, `all()`, `forEach()`, `ofType()`

2. **RelationDescriptor** - Metadata about a single relation
   - Location: `src/Schemas/Relations/RelationDescriptor.php`
   - Status: Production-ready
   - Stores: name, type (BelongsTo/HasMany/etc), targetModel, keys, pivot

3. **RelationIntrospector** - Discovers relations from Eloquent models
   - Location: `src/Providers/Eloquent/Introspectors/Relations/RelationIntrospector.php`
   - Status: Production-ready
   - How: Parses source code without DB connection
   - Supports: BelongsTo, HasMany, HasOne, BelongsToMany

4. **SchemaProvider Infrastructure** - Complete
   - EloquentSchemaProvider auto-discovers models
   - ModelMetadata serializes RelationGraph for caching
   - SliceSource.relations() exposes RelationGraph

### What's Missing (❌ Not Implemented)

**JoinResolver Component** - The core join resolution engine

Location: `/src/Engine/Resolvers/JoinResolver.php` (currently EMPTY directory)

Three methods needed:

1. **findJoinPath(from, to, allTables): ?array**
   - Finds shortest join path using BFS
   - Returns [{from: ..., to: ..., relation: ...}] or null

2. **buildJoinGraph(tables): array**
   - Connects multiple tables
   - Uses findJoinPath iteratively
   - Returns deduplicated join specifications

3. **applyJoins(query, joinPath): Query**
   - Applies SQL JOINs to Laravel query builder
   - Handles BelongsTo, HasMany, BelongsToMany
   - Returns modified query

---

## Data Flow

```
Eloquent Model (Order)
  ↓ (has method: public function customer(): BelongsTo)
  ↓
RelationIntrospector (source code parsing)
  ↓
RelationDescriptor {name, type, targetModel, keys}
  ↓
RelationGraph (collected per table)
  ↓
SliceSource.relations() (exposed via interface)
  ↓
JoinResolver.buildJoinGraph(tables) ← NEEDS TO BE BUILT
  ↓ (returns join specifications)
JoinResolver.applyJoins(query, specs) ← NEEDS TO BE BUILT
  ↓ (applies SQL JOINs)
Final Query with JOINs
```

---

## Quick Reference Tables

### RelationType Enum

```php
enum RelationType: string
{
    case BelongsTo = 'belongs_to';        // FK on source table
    case HasMany = 'has_many';            // FK on target table
    case HasOne = 'has_one';              // FK on target table (1 result)
    case BelongsToMany = 'belongs_to_many'; // Through pivot table
    case MorphTo = 'morph_to';            // Polymorphic (partial)
    case MorphMany = 'morph_many';        // Polymorphic (partial)
}
```

### Keys Array Structure

**BelongsTo:**
```php
['foreign' => 'customer_id', 'owner' => 'id']
```

**HasMany:**
```php
['foreign' => 'order_id', 'local' => 'id']
```

**BelongsToMany:**
```php
['foreign' => 'student_id', 'related' => 'course_id']
// Plus: pivot = 'enrollments'
```

### Join Specification Format

```php
[
    'from' => 'orders',
    'to' => 'customers',
    'relation' => RelationDescriptor { ... }
]
```

---

## Key Files

| File | Purpose | Status |
|------|---------|--------|
| `src/Schemas/Relations/RelationGraph.php` | Relation container | ✅ Complete |
| `src/Schemas/Relations/RelationDescriptor.php` | Relation metadata | ✅ Complete |
| `src/Providers/Eloquent/Introspectors/Relations/RelationIntrospector.php` | Discovery | ✅ Complete |
| `src/Contracts/SliceSource.php` | Table interface | ✅ Complete |
| `src/Schemas/MetadataBackedTable.php` | Table wrapper | ✅ Complete |
| `src/Engine/Resolvers/JoinResolver.php` | **MISSING** | ❌ Empty |

---

## Test Workbench Models

For testing join resolution:

```
Order
├── customer → Customer (BelongsTo)
└── items → OrderItem (HasMany)

OrderItem
├── order → Order (BelongsTo)
└── product → Product (BelongsTo)

Customer
└── orders → Order (HasMany)

Product (no relations)
User (no relations)
AdSpend (no relations, for CrossJoin future)
```

---

## Building JoinResolver - Quick Checklist

- [ ] Create `/src/Engine/Resolvers/JoinResolver.php`
- [ ] Implement `findJoinPath()` with BFS algorithm
- [ ] Implement `buildJoinGraph()` with greedy approach
- [ ] Implement `applyJoins()` with relation type handling
- [ ] Support BelongsTo joins
- [ ] Support HasMany joins
- [ ] Support BelongsToMany joins (through pivot)
- [ ] Add unit tests for all three methods
- [ ] Add integration tests with real queries
- [ ] Wire into QueryBuilder
- [ ] Test with multi-table queries

---

## Algorithm Reference

### BFS Pseudocode

```
ALGORITHM findJoinPath(from, to, allTables)
    Queue ← [(from, [])]
    Visited ← {from: true}
    
    WHILE Queue not empty:
        (current, path) ← dequeue
        
        FOR EACH relation in current.relations():
            target ← relation.target()
            
            IF visited[target]: CONTINUE
            
            newPath ← path + [{from: current, to: target, relation}]
            
            IF target == to: RETURN newPath
            
            visited[target] ← true
            enqueue((target, newPath))
    
    RETURN null
```

**Complexity:** O(V + E) - Linear in vertices and edges  
**Properties:** Finds shortest path, prevents cycles, early termination

### Greedy Graph Building

```
ALGORITHM buildJoinGraph(tables)
    IF count(tables) ≤ 1: RETURN []
    
    connected ← [tables[0]]
    allJoins ← {}
    
    FOR i = 1 TO count(tables):
        target ← tables[i]
        
        FOR source IN connected tables:
            path ← findJoinPath(source, target)
            IF path:
                ADD all joins to allJoins (with dedup by "from→to")
                ADD target to connected
                BREAK
    
    RETURN values(allJoins)
```

**Complexity:** O(n² · (V+E))  
**Greedy:** Uses first found path, not globally optimal

---

## Example: Multi-Table Query

**Query:**
```php
Slice::query()
    ->metrics([
        Sum::make('orders.total'),
        Count::make('order_items.id')
    ])
    ->dimensions([Dimension::make('customer')])
    ->get();
```

**Steps:**

1. Normalize → Tables needed: [orders, order_items, customers]

2. `buildJoinGraph([orders, order_items, customers])`:
   - connected = ['orders']
   - Target: order_items
   - `findJoinPath(orders, order_items)` finds: orders→order_items (HasMany)
   - connected = ['orders', 'order_items']
   - Target: customers
   - `findJoinPath(orders, customers)` finds: orders→customers (BelongsTo)
   - Result: [orders→order_items, orders→customers]

3. `applyJoins(query, specs)`:
   - orders→order_items is HasMany → `JOIN order_items ON orders.id = order_items.order_id`
   - orders→customers is BelongsTo → `JOIN customers ON orders.customer_id = customers.id`

4. Final SQL:
```sql
SELECT SUM(orders.total), COUNT(order_items.id), customers.id
FROM orders
JOIN order_items ON orders.id = order_items.order_id
JOIN customers ON orders.customer_id = customers.id
GROUP BY customers.id
```

---

## Next Steps

1. Read the full guide: `planning/RELATION_GRAPH_AND_JOIN_RESOLVER_SUMMARY.md`
2. Understand BFS algorithm in Part 5
3. Review data structures in Part 6
4. Follow implementation steps in Part 9
5. Write tests based on Part 10
6. Handle edge cases from Part 11

---

**Generated:** November 9, 2025  
**Project:** Laravel Analytics Builder (Slice)  
**Status:** Phase 3 Complete - Ready for JoinResolver Implementation
