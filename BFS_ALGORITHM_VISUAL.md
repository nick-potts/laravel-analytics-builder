# BFS Algorithm Visual Guide & Code Reference

## BFS Algorithm Visualization

### Complete BFS Flow

```
┌──────────────────────────────────────────────────────────────────┐
│                     findJoinPath() - BFS Core                    │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  INPUT: from=OrderItemsTable, to=CustomersTable                │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │ STEP 0: Initialize                                      │  │
│  ├─────────────────────────────────────────────────────────┤  │
│  │ queue = [                                               │  │
│  │   [OrderItemsTable instance, []]  ← Start here         │  │
│  │ ]                                                       │  │
│  │ visited = { order_items: true }                        │  │
│  └─────────────────────────────────────────────────────────┘  │
│                          ↓                                       │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │ STEP 1: Dequeue (OrderItemsTable, [])                  │  │
│  ├─────────────────────────────────────────────────────────┤  │
│  │                                                         │  │
│  │ Explore relations():                                    │  │
│  │ ┌────────────────────────────────────────────────────┐ │  │
│  │ │ Relation 1: 'order' → OrdersTable                 │ │  │
│  │ ├────────────────────────────────────────────────────┤ │  │
│  │ │ • Not in visited? YES ✓                           │ │  │
│  │ │ • Is target? NO                                   │ │  │
│  │ │ • newPath: [order_items→orders]                 │ │  │
│  │ │ • visited['orders'] = true                        │ │  │
│  │ │ • Enqueue: (OrdersTable, [order_items→orders])  │ │  │
│  │ └────────────────────────────────────────────────────┘ │  │
│  │                                                         │  │
│  │ ┌────────────────────────────────────────────────────┐ │  │
│  │ │ Relation 2: 'product' → ProductsTable             │ │  │
│  │ ├────────────────────────────────────────────────────┤ │  │
│  │ │ • Not in visited? YES ✓                           │ │  │
│  │ │ • Is target? NO                                   │ │  │
│  │ │ • newPath: [order_items→products]               │ │  │
│  │ │ • visited['products'] = true                      │ │  │
│  │ │ • Enqueue: (ProductsTable, [order_items→products])│ │  │
│  │ └────────────────────────────────────────────────────┘ │  │
│  │                                                         │  │
│  │ queue.length = 2                                       │  │
│  │ visited = {                                            │  │
│  │   order_items: true,                                 │  │
│  │   orders: true,                                      │  │
│  │   products: true                                     │  │
│  │ }                                                     │  │
│  └─────────────────────────────────────────────────────────┘  │
│                          ↓                                       │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │ STEP 2: Dequeue (OrdersTable, [order_items→orders])   │  │
│  ├─────────────────────────────────────────────────────────┤  │
│  │                                                         │  │
│  │ Explore relations():                                    │  │
│  │ ┌────────────────────────────────────────────────────┐ │  │
│  │ │ Relation 1: 'customer' → CustomersTable           │ │  │
│  │ ├────────────────────────────────────────────────────┤ │  │
│  │ │ • Not in visited? YES ✓                           │ │  │
│  │ │ • Is target? YES!!! ✓✓✓                          │ │  │
│  │ │ • newPath: [order_items→orders, orders→customers]│ │  │
│  │ │ • RETURN newPath (FOUND!)                         │ │  │
│  │ └────────────────────────────────────────────────────┘ │  │
│  │                                                         │  │
│  │ ✓ SUCCESS! PATH FOUND WITH 2 HOPS                     │  │
│  └─────────────────────────────────────────────────────────┘  │
│                          ↓                                       │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │ RETURN:                                                 │  │
│  │ [                                                       │  │
│  │   {                                                     │  │
│  │     from: 'order_items',                              │  │
│  │     to: 'orders',                                      │  │
│  │     relation: BelongsTo instance                      │  │
│  │   },                                                   │  │
│  │   {                                                     │  │
│  │     from: 'orders',                                    │  │
│  │     to: 'customers',                                   │  │
│  │     relation: BelongsTo instance                      │  │
│  │   }                                                     │  │
│  │ ]                                                       │  │
│  └─────────────────────────────────────────────────────────┘  │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

### Key Points in BFS Algorithm

```
1. QUEUE STRUCTURE:
   Each item = [CurrentTable, PathToCurrentTable]
   
   Example:
   [(OrderItemsTable, []),
    (OrdersTable, [{from: order_items, to: orders, relation: ...}]),
    (ProductsTable, [{from: order_items, to: products, relation: ...}])]


2. VISITED SET:
   Tracks tables already explored (prevents cycles and redundant work)
   
   visited[table_name] = true
   
   Example:
   {
     'order_items': true,
     'orders': true,
     'products': true,
     'customers': true
   }


3. TERMINATION CONDITIONS:
   
   ✓ FOUND: Related table name === target table name
     → Return path immediately
   
   ✗ NOT FOUND: queue becomes empty
     → Return null (no path exists)


4. DEQUEUE ORDER:
   array_shift($queue) - FIFO (First In First Out)
   Ensures we explore tables level by level (breadth-first)
   
   Level 0: [OrderItemsTable]
   Level 1: [OrdersTable, ProductsTable]
   Level 2: [CustomersTable, ...]
```

---

## Build Join Graph Algorithm Visualization

### Greedy Graph Building Flow

```
┌──────────────────────────────────────────────────────────────────┐
│              buildJoinGraph([Orders, OrderItems, Products])      │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│ ┌────────────────────────────────────────────────────────────┐ │
│ │ STEP 0: Initialize                                         │ │
│ ├────────────────────────────────────────────────────────────┤ │
│ │ connectedTables = ['orders']      ← Start with first table│ │
│ │ allJoins = {}                     ← Empty join collection │ │
│ │ targetTables = [OrderItems, Products]  ← To connect       │ │
│ └────────────────────────────────────────────────────────────┘ │
│                          ↓                                       │
│ ┌────────────────────────────────────────────────────────────┐ │
│ │ LOOP i=1: Connect OrderItems table                         │ │
│ ├────────────────────────────────────────────────────────────┤ │
│ │                                                            │ │
│ │ Try each connected table as source:                        │ │
│ │ ┌──────────────────────────────────────────────────────┐ │ │
│ │ │ Source: Orders (in connectedTables)                 │ │ │
│ │ │ Target: OrderItems (NOT in connectedTables)         │ │ │
│ │ │                                                      │ │ │
│ │ │ findJoinPath(Orders → OrderItems):                 │ │ │
│ │ │   Queue: [(Orders, [])]                            │ │ │
│ │ │   Visit Orders relations:                          │ │ │
│ │ │     - 'customer' → Customers (not target)          │ │ │
│ │ │     - 'items' → OrderItems (TARGET FOUND!)         │ │ │
│ │ │   Return [{                                        │ │ │
│ │ │     from: 'orders',                               │ │ │
│ │ │     to: 'order_items',                            │ │ │
│ │ │     relation: HasMany(...)                        │ │ │
│ │ │   }]                                              │ │ │
│ │ │                                                      │ │ │
│ │ │ Path found! ✓                                       │ │ │
│ │ └──────────────────────────────────────────────────────┘ │ │
│ │                                                            │ │
│ │ Add path to allJoins (avoid duplicates):                  │ │
│ │ joinKey = 'orders->order_items'                          │ │
│ │ allJoins[joinKey] = {from: orders, to: order_items, ...}│ │
│ │                                                            │ │
│ │ connectedTables.push('order_items')                       │ │
│ │ connectedTables = ['orders', 'order_items']              │ │
│ │ break  ← Move to next target                             │ │
│ └────────────────────────────────────────────────────────────┘ │
│                          ↓                                       │
│ ┌────────────────────────────────────────────────────────────┐ │
│ │ LOOP i=2: Connect Products table                           │ │
│ ├────────────────────────────────────────────────────────────┤ │
│ │                                                            │ │
│ │ Try each connected table as source:                        │ │
│ │ ┌──────────────────────────────────────────────────────┐ │ │
│ │ │ Source: Orders (in connectedTables)                 │ │ │
│ │ │ Target: Products (NOT in connectedTables)           │ │ │
│ │ │                                                      │ │ │
│ │ │ findJoinPath(Orders → Products):                   │ │ │
│ │ │   No direct relation from Orders to Products       │ │ │
│ │ │   Search full graph:                               │ │ │
│ │ │   Orders → OrderItems → Products                   │ │ │
│ │ │   Path found with 2 hops! ✓                        │ │ │
│ │ │   Return [                                         │ │ │
│ │ │     {from: orders, to: order_items, ...},         │ │ │
│ │ │     {from: order_items, to: products, ...}        │ │ │
│ │ │   ]                                                 │ │ │
│ │ └──────────────────────────────────────────────────────┘ │ │
│ │                                                            │ │
│ │ Add path to allJoins:                                     │ │
│ │ - joinKey = 'orders->order_items'                        │ │
│ │   Already exists! Skip (deduplication)                    │ │
│ │ - joinKey = 'order_items->products'                      │ │
│ │   New! Add it                                            │ │
│ │                                                            │ │
│ │ allJoins = {                                             │ │
│ │   'orders->order_items': {...},                         │ │
│ │   'order_items->products': {...}                        │ │
│ │ }                                                         │ │
│ │                                                            │ │
│ │ connectedTables = ['orders', 'order_items', 'products'] │ │
│ │ break                                                     │ │
│ └────────────────────────────────────────────────────────────┘ │
│                          ↓                                       │
│ ┌────────────────────────────────────────────────────────────┐ │
│ │ RETURN: array_values(allJoins)                            │ │
│ │ [                                                          │ │
│ │   {from: 'orders', to: 'order_items', relation: ...},   │ │
│ │   {from: 'order_items', to: 'products', relation: ...}  │ │
│ │ ]                                                          │ │
│ └────────────────────────────────────────────────────────────┘ │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## Relation Detection Paths

### BelongsTo Join Visualization

```
┌─────────────────────┐
│   orders table      │
│ ┌─────────────────┐ │
│ │ id (PK)         │ │
│ │ customer_id(FK) ├─┼───────────┐
│ │ total           │ │           │
│ └─────────────────┘ │           │ foreignKey: 'customer_id'
└─────────────────────┘           │ ownerKey: 'id'
                                  │
SQL JOIN clause:                  │
┌────────────────────────────────┐│
│ JOIN customers ON              ││
│   orders.customer_id =         │ │
│   customers.id                 │ │
└────────────────────────────────┘│
                                  │
                                  ↓
                        ┌─────────────────────┐
                        │ customers table     │
                        │ ┌─────────────────┐ │
                        │ │ id (PK)         │ │
                        │ │ name            │ │
                        │ │ email           │ │
                        │ └─────────────────┘ │
                        └─────────────────────┘

Code in OrdersTable:
  $this->belongsTo(CustomersTable::class, 'customer_id')
                                         └─── FK in orders table

Applied in applyJoins():
  query.join(
    'customers',            // to table
    'orders.customer_id',   // from.foreignKey()
    '=',
    'customers.id'          // to.ownerKey()
  )
```

### HasMany Join Visualization

```
┌─────────────────────┐
│   orders table      │
│ ┌─────────────────┐ │
│ │ id (PK)         │←┼─────────────┐
│ │ total           │ │             │
│ │ status          │ │             │ localKey: 'id'
│ └─────────────────┘ │             │ foreignKey: 'order_id'
└─────────────────────┘             │
                                    │
SQL JOIN clause:                    │
┌────────────────────────────────┐  │
│ JOIN order_items ON            │  │
│   orders.id =                  │  │
│   order_items.order_id         │  │
└────────────────────────────────┘  │
                                    │
                                    ↓
                        ┌─────────────────────┐
                        │ order_items table   │
                        │ ┌─────────────────┐ │
                        │ │ id (PK)         │ │
                        │ │ order_id (FK)   │ │
                        │ │ product_id (FK) │ │
                        │ │ quantity        │ │
                        │ │ price           │ │
                        │ └─────────────────┘ │
                        └─────────────────────┘

Code in OrdersTable:
  $this->hasMany(OrderItemsTable::class, 'order_id')
                                         └─── FK in order_items table

Applied in applyJoins():
  query.join(
    'order_items',          // to table
    'orders.id',            // from.localKey()
    '=',
    'order_items.order_id'  // to.foreignKey()
  )
```

---

## Complete Code Reference

### Full JoinResolver Implementation

```php
<?php
namespace NickPotts\Slice\Engine;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Tables\BelongsTo;
use NickPotts\Slice\Tables\HasMany;
use NickPotts\Slice\Tables\Table;

class JoinResolver
{
    /**
     * Find the join path between two tables using BFS.
     * Returns SHORTEST path (minimum number of hops).
     */
    public function findJoinPath(Table $from, Table $to, array $allTables): ?array
    {
        // Same table = no path needed
        if ($from->table() === $to->table()) {
            return [];
        }

        // Initialize BFS: queue contains [Table, PathToTable] pairs
        $queue = [[$from, []]];
        $visited = [$from->table() => true];

        // BFS main loop
        while (! empty($queue)) {
            // Dequeue (FIFO = breadth-first)
            [$currentTable, $path] = array_shift($queue);

            // Explore all relations from current table
            foreach ($currentTable->relations() as $relationName => $relation) {
                $relatedTableClass = $relation->table();
                $relatedTable = new $relatedTableClass;
                $relatedTableName = $relatedTable->table();

                // Skip if already visited (prevent cycles)
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
                    return $newPath;  // SUCCESS!
                }

                // Mark visited and enqueue for exploration
                $visited[$relatedTableName] = true;
                $queue[] = [$relatedTable, $newPath];
            }
        }

        return null;  // No path found
    }

    /**
     * Build a join graph connecting all required tables.
     * Uses greedy approach: connect tables iteratively via shortest paths.
     */
    public function buildJoinGraph(array $tables): array
    {
        // Single table = no joins needed
        if (count($tables) <= 1) {
            return [];
        }

        $allJoins = [];
        $connectedTables = [$tables[0]->table()];

        // Iteratively connect remaining tables
        for ($i = 1; $i < count($tables); $i++) {
            $targetTable = $tables[$i];

            // Try to find a path from any connected table to the target
            foreach ($tables as $sourceTable) {
                if (in_array($sourceTable->table(), $connectedTables) 
                    && $sourceTable->table() !== $targetTable->table()) {
                    
                    // Find path from source to target
                    $path = $this->findJoinPath($sourceTable, $targetTable, $tables);

                    if ($path !== null) {
                        // Add all joins in this path (with deduplication)
                        foreach ($path as $join) {
                            $joinKey = $join['from'].'->'.$join['to'];
                            if (! isset($allJoins[$joinKey])) {
                                $allJoins[$joinKey] = $join;
                            }
                        }

                        // Mark target as connected
                        $connectedTables[] = $targetTable->table();
                        break;  // Move to next target
                    }
                }
            }
        }

        return array_values($allJoins);
    }

    /**
     * Apply joins to a query adapter based on the join path.
     * Converts join specifications to SQL JOIN clauses.
     */
    public function applyJoins(QueryAdapter $query, array $joinPath): QueryAdapter
    {
        foreach ($joinPath as $join) {
            $relation = $join['relation'];
            $fromTable = $join['from'];
            $toTable = $join['to'];

            // Handle BelongsTo: FK is on source table
            if ($relation instanceof BelongsTo) {
                $query->join(
                    $toTable,
                    "{$fromTable}.{$relation->foreignKey()}",
                    '=',
                    "{$toTable}.{$relation->ownerKey()}"
                );
            } 
            // Handle HasMany: FK is on target table
            elseif ($relation instanceof HasMany) {
                $query->join(
                    $toTable,
                    "{$fromTable}.{$relation->localKey()}",
                    '=',
                    "{$toTable}.{$relation->foreignKey()}"
                );
            }
            // TODO: Add support for BelongsToMany and CrossJoin
        }

        return $query;
    }
}
```

---

## Key Constants & Data Structures

### Join Path Structure

```php
$joinPath = [
    [
        'from' => 'orders',                    // Source table name
        'to' => 'customers',                   // Target table name  
        'relation' => BelongsTo instance       // Relation object with key info
    ],
    [
        'from' => 'orders',
        'to' => 'order_items',
        'relation' => HasMany instance
    ]
];
```

### Visited Set Example

```php
$visited = [
    'orders' => true,
    'customers' => true,
    'order_items' => true,
    'products' => true,
];
```

### Queue Item Structure

```php
$queueItem = [
    OrderItemsTable instance,  // Current table being explored
    [
        [
            'from' => 'orders',
            'to' => 'order_items',
            'relation' => BelongsTo instance
        ]
    ]  // Path taken to reach this table
];
```

