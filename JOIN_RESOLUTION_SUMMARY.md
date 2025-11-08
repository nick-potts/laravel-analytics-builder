# Slice Join Resolution - Quick Reference Summary

## Files & Locations

| Component | File Path | Purpose |
|-----------|-----------|---------|
| **JoinResolver** | `src/Engine/JoinResolver.php` | Core join resolution logic |
| **Table** | `src/Tables/Table.php` | Base class for table definitions |
| **BelongsTo** | `src/Tables/BelongsTo.php` | 1:1 reverse relationship |
| **HasMany** | `src/Tables/HasMany.php` | 1:N forward relationship |
| **BelongsToMany** | `src/Tables/BelongsToMany.php` | M:N relationship (stub) |
| **CrossJoin** | `src/Tables/CrossJoin.php` | Custom cross-domain joins |
| **QueryBuilder** | `src/Engine/QueryBuilder.php` | Uses JoinResolver |
| **Test** | `tests/Unit/QueryTest.php` | Integration tests |
| **Workbench** | `workbench/app/Analytics/` | Real examples |

---

## Core Methods & Signatures

### JoinResolver::findJoinPath()

```php
public function findJoinPath(Table $from, Table $to, array $allTables): ?array
```

**Input**: Source table, target table, all available tables
**Output**: Array of join specs OR null if no path exists
**Algorithm**: BFS (breadth-first search)
**Complexity**: O(V + E) where V=tables, E=relations
**Returns**: Shortest path between tables (minimum hops)

---

### JoinResolver::buildJoinGraph()

```php
public function buildJoinGraph(array $tables): array
```

**Input**: Array of Table instances needed for query
**Output**: Array of join specifications to apply
**Algorithm**: Greedy (iteratively connect tables via BFS)
**Complexity**: O(n²·(V+E)) in worst case
**Key Feature**: Deduplicates joins by direction (A→B counted once)

---

### JoinResolver::applyJoins()

```php
public function applyJoins(QueryAdapter $query, array $joinPath): QueryAdapter
```

**Input**: QueryAdapter, array of join specifications
**Output**: Modified QueryAdapter with joins applied
**Action**: Converts join specs to SQL JOIN clauses
**Status**: Implements BelongsTo + HasMany only
**Missing**: BelongsToMany, CrossJoin

---

## BFS Algorithm - Step by Step

### Queue-Based Exploration

```
1. Initialize:
   queue = [[StartTable, []]]
   visited = {StartTable: true}

2. While queue not empty:
   a. Dequeue current = [CurrentTable, PathSoFar]
   b. For each relation in CurrentTable.relations():
      - Get related table
      - Skip if visited (cycle prevention)
      - Build newPath = PathSoFar + [current→related]
      - If related == target: RETURN newPath (SUCCESS!)
      - Mark related visited
      - Enqueue [related, newPath]

3. After loop:
   RETURN null (no path exists)
```

### Time Complexity

- **Per call**: O(V + E) where V = table count, E = relation count
- **BFS property**: Explores tables level by level
- **Early termination**: Returns immediately when target found
- **No backtracking**: FIFO queue ensures breadth-first order

---

## Relation Type Decision Tree

### BelongsTo

```
✓ When: Child table has foreign key to parent
✓ Table definition: $this->belongsTo(ParentTable::class, 'fk_column')
✓ FK position: In source (child) table
✓ SQL: JOIN parent ON child.fk = parent.id
✓ Implemented: YES
```

### HasMany

```
✓ When: Parent table linked to many child records
✓ Table definition: $this->hasMany(ChildTable::class, 'fk_column')
✓ FK position: In target (child) table
✓ SQL: JOIN children ON parent.id = children.fk
✓ Implemented: YES
```

### BelongsToMany

```
✓ When: M:N relationship through pivot table
✓ Table definition: $this->belongsToMany(Target::class, 'pivot', 'fk1', 'fk2')
✓ FK position: Both in pivot table
✓ SQL: JOIN pivot ON ... JOIN target ON ...
✗ Implemented: NO (stub only)
```

### CrossJoin

```
✓ When: Unrelated tables need join (e.g., date-based)
✓ Table definition: $this->crossJoin(OtherTable::class, 'left_col', 'right_col')
✓ FK position: Custom columns
✓ SQL: JOIN other ON left_col = right_col
✗ Implemented: NO (not integrated)
```

---

## Data Flow Through JoinResolver

```
┌─────────────────────────────┐
│ QueryBuilder.buildDatabasePlan()
│ ├─ Extracts tables from metrics
│ └─ if count(tables) > 1:
└──────────┬────────────────────┘
           │
           ↓
┌─────────────────────────────┐
│ JoinResolver.buildJoinGraph()
│ ├─ Starts with tables[0] as connected
│ ├─ Iterates through remaining tables
│ ├─ For each target:
│ │  └─ Calls findJoinPath(source, target)
│ │     ├─ Uses BFS to explore from source
│ │     └─ Returns shortest path OR null
│ └─ Deduplicates and returns all joins
└──────────┬────────────────────┘
           │
           ↓
┌─────────────────────────────┐
│ JoinResolver.applyJoins()
│ ├─ Loops through join path
│ ├─ For each join:
│ │  ├─ if BelongsTo: uses foreignKey + ownerKey
│ │  ├─ if HasMany: uses localKey + foreignKey
│ │  └─ Calls query.join()
│ └─ Returns modified query
└──────────┬────────────────────┘
           │
           ↓
┌─────────────────────────────┐
│ QueryAdapter.join() (Laravel)
│ ├─ Maps to Illuminate\Query\Builder
│ └─ Generates SQL JOIN clause
└─────────────────────────────┘
```

---

## SQL Generation Examples

### BelongsTo Example

**Definition**:
```php
class OrdersTable {
    public function relations() {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id')
        ];
    }
}
```

**Generated SQL**:
```sql
JOIN customers ON orders.customer_id = customers.id
```

**Why**: foreignKey is `customer_id` (in orders), ownerKey is `id` (in customers)

---

### HasMany Example

**Definition**:
```php
class OrdersTable {
    public function relations() {
        return [
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id')
        ];
    }
}
```

**Generated SQL**:
```sql
JOIN order_items ON orders.id = order_items.order_id
```

**Why**: localKey is `id` (in orders), foreignKey is `order_id` (in order_items)

---

## Critical Implementation Details

### What Works

| Feature | Status | Notes |
|---------|--------|-------|
| BFS pathfinding | ✅ Fully working | O(V+E) complexity |
| BelongsTo joins | ✅ Fully working | FK on source table |
| HasMany joins | ✅ Fully working | FK on target table |
| Cycle prevention | ✅ Fully working | Visited set tracking |
| Deduplication | ✅ Fully working | By direction (A→B) |
| Database integration | ✅ Fully working | Via QueryAdapter |

### What Doesn't Work

| Feature | Status | Blocker |
|---------|--------|---------|
| BelongsToMany | ❌ Stub | Missing join logic for pivot |
| CrossJoin | ❌ Not integrated | Not discovered/applied |
| Multiple ON conditions | ❌ Not supported | Single = comparison only |
| LEFT/RIGHT JOINs | ❌ Not supported | Always INNER JOIN |
| Conditional joins | ❌ Not supported | No custom WHERE in joins |

---

## Debugging Checklist

### Issue: "Unable to determine join path"

**Causes**:
1. Table not in relations() of any connected table
2. Circular/disconnected schema
3. Missing relation definition

**Check**:
- [ ] Is the target table in any relations()?
- [ ] Is there a chain from primary to target?
- [ ] Are relation table() class names correct?

### Issue: Wrong data in results

**Causes**:
1. Foreign key column name mismatch
2. Wrong ownerKey/localKey in relation
3. Multiple matching join paths (greedy picks first)

**Check**:
- [ ] Does FK name match in table and relation?
- [ ] Is ownerKey/localKey correct?
- [ ] Is join SQL correct in generated query?

### Issue: Performance problems

**Causes**:
1. Deep join chains (many hops)
2. Cartesian product from HasMany
3. Missing indexes on FK columns

**Check**:
- [ ] How many joins in the path?
- [ ] Does HasMany cause row multiplication?
- [ ] Are FK columns indexed?

---

## Code Snippets for Quick Reference

### Check if Path Exists

```php
$resolver = new JoinResolver();
$path = $resolver->findJoinPath($table1, $table2, [$table1, $table2]);

if ($path === null) {
    echo "No join path found between tables";
} else {
    echo "Found path with " . count($path) . " joins";
}
```

### Manual Join Building

```php
$resolver = new JoinResolver();
$tables = [new OrdersTable(), new OrderItemsTable()];

$joinGraph = $resolver->buildJoinGraph($tables);

foreach ($joinGraph as $join) {
    echo sprintf(
        "%s (%s) → %s (%s)\n",
        $join['from'],
        get_class($join['relation']),
        $join['to'],
        $join['relation']->table()
    );
}
```

### Check Relation Type

```php
$relation = $currentTable->relations()['customer'];

if ($relation instanceof BelongsTo) {
    echo "Foreign key: " . $relation->foreignKey();
    echo "Owner key: " . $relation->ownerKey();
} elseif ($relation instanceof HasMany) {
    echo "Local key: " . $relation->localKey();
    echo "Foreign key: " . $relation->foreignKey();
}
```

---

## Key Algorithms Summary

### Algorithm 1: findJoinPath (BFS)

```
Goal: Find shortest path from table A to table B
Time: O(V + E)
Space: O(V)
Returns: Exact shortest path OR null
```

### Algorithm 2: buildJoinGraph (Greedy)

```
Goal: Connect all N tables with minimum joins
Time: O(n² · (V+E))
Space: O(n·V)
Returns: Connected graph (not always globally optimal)
```

### Algorithm 3: applyJoins (Linear)

```
Goal: Convert join specs to SQL
Time: O(n) where n = joins
Space: O(1)
Returns: Query builder with JOINs applied
```

---

## Integration Points

### In QueryBuilder

1. **buildDatabasePlan** (line 88)
   ```php
   $joinPath = $this->joinResolver->buildJoinGraph($tables);
   $query = $this->joinResolver->applyJoins($query, $joinPath);
   ```

2. **buildSoftwareJoinPlan** (line 114)
   ```php
   $joinGraph = $this->joinResolver->buildJoinGraph($tables);
   // Used to build software join metadata
   ```

3. **buildBaseAggregationCTE** (line 613)
   ```php
   $joinPath = $this->joinResolver->buildJoinGraph($tables);
   $query = $this->joinResolver->applyJoins($query, $joinPath);
   ```

---

## Testing Strategy

### Unit Test Areas

- [ ] BFS finds correct shortest path
- [ ] BFS returns null for unconnected tables
- [ ] buildJoinGraph connects all tables
- [ ] Deduplication works correctly
- [ ] applyJoins generates correct SQL

### Integration Test

- [ ] Orders + OrderItems query produces correct results
- [ ] Software join fallback matches database join
- [ ] Dimension grouping works with joins
- [ ] Filter application works across joined tables

---

## Performance Considerations

### Complexity Analysis

| Operation | Best | Average | Worst | Note |
|-----------|------|---------|-------|------|
| findJoinPath | O(1) | O(V+E) | O(V+E) | Early termination possible |
| buildJoinGraph | O(n) | O(n²·(V+E)) | O(n²·(V+E)) | n = table count |
| applyJoins | O(j) | O(j) | O(j) | j = join count |

### Optimization Opportunities

1. **Cache join paths** - Precompute common paths
2. **Optimize BFS** - Use queue data structure (array_shift is O(n))
3. **Lazy evaluation** - Compute joins only when needed
4. **Early termination** - Return as soon as one path found per target

---

## Future Enhancement Ideas

### Must Have
1. BelongsToMany support (pivot table joins)
2. CrossJoin integration (ad-hoc joins)

### Should Have
3. LEFT/RIGHT JOIN support (optional relations)
4. Multiple ON conditions (complex joins)
5. Path optimization (Dijkstra's algorithm)

### Nice to Have
6. Circular join detection
7. Join path caching
8. Query optimization hints
9. Join statistics (cardinality)

