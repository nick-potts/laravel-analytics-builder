# Relation Chain Walking Architecture

**Purpose:** Support filter traversal across multi-hop relations (e.g., `orders` → `order_lines` → `products`)

**Status:** Design Phase (Future Enhancement)

---

## Current State

Currently, Slice supports:
- ✅ Direct table filters: `Dimension::make('status')->only(['completed'])`
- ✅ Single-hop joins: `orders` → `customers`
- ❌ Relation chain filters: `.where('customer.is_new_customer', true)`
- ❌ Multi-hop joins: `orders` → `order_lines` → `products`

---

## Desired End State

```php
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->where('customer.country', 'US') // ← Filter on related table
    ->where('order_lines.product.category', 'Electronics') // ← Multi-hop filter
    ->get();
```

**Auto-detected behavior:**
1. Parse `customer.country` → Traverse `orders.customer` relation
2. Parse `order_lines.product.category` → Traverse `orders.order_lines.product` relations
3. Auto-apply joins: `orders` → `customers`, `orders` → `order_lines`, `order_lines` → `products`
4. Apply WHERE clauses at correct positions

---

## Architecture Design

### Component 1: RelationChainParser

**Purpose:** Parse dot notation into relation chain

```php
class RelationChainParser {
    /**
     * Parse relation chain notation.
     *
     * Examples:
     * - 'customer.country' → ['customer'], 'country'
     * - 'order_lines.product.category' → ['order_lines', 'product'], 'category'
     */
    public function parse(string $notation): array {
        $parts = explode('.', $notation);

        if (count($parts) < 2) {
            throw new InvalidArgumentException(
                "Invalid relation chain: {$notation}. Expected format: 'relation.column' or 'relation.relation.column'"
            );
        }

        $column = array_pop($parts); // Last part is column
        $relationChain = $parts; // Everything else is relations

        return [
            'relations' => $relationChain,
            'column' => $column,
        ];
    }

    /**
     * Resolve relation chain to tables.
     *
     * @param TableContract $startTable Starting table
     * @param array $relationChain Relation names
     * @return array{tables: TableContract[], column: string}
     */
    public function resolve(TableContract $startTable, array $relationChain, string $column): array {
        $tables = [$startTable];
        $currentTable = $startTable;

        foreach ($relationChain as $relationName) {
            $relations = $currentTable->relations();

            if (!isset($relations[$relationName])) {
                throw new InvalidArgumentException(
                    "Relation '{$relationName}' not found on table '{$currentTable->table()}'"
                );
            }

            $relation = $relations[$relationName];
            $relatedTableClass = $relation->table();
            $relatedTable = new $relatedTableClass;

            $tables[] = $relatedTable;
            $currentTable = $relatedTable;
        }

        return [
            'tables' => $tables,
            'column' => $column,
            'finalTable' => $currentTable,
        ];
    }
}
```

### Component 2: RelationChainFilter

**Purpose:** Apply filters across relation chains

```php
class RelationChainFilter {
    protected RelationChainParser $parser;
    protected JoinResolver $joinResolver;

    /**
     * Apply a filter across a relation chain.
     *
     * @param QueryAdapter $query
     * @param string $chainNotation e.g., 'customer.country'
     * @param string $operator
     * @param mixed $value
     */
    public function apply(
        QueryAdapter $query,
        TableContract $baseTable,
        string $chainNotation,
        string $operator,
        mixed $value
    ): void {
        // Parse notation
        $parsed = $this->parser->parse($chainNotation);
        $relationChain = $parsed['relations'];
        $column = $parsed['column'];

        // Resolve chain to tables
        $resolved = $this->parser->resolve($baseTable, $relationChain, $column);
        $tables = $resolved['tables'];
        $finalTable = $resolved['finalTable'];

        // Ensure joins exist for all tables in chain
        if (count($tables) > 1) {
            $joinPath = $this->joinResolver->buildJoinGraph($tables);
            $this->joinResolver->applyJoins($query, $joinPath);
        }

        // Apply WHERE clause on final table
        $fullColumn = $finalTable->table() . '.' . $column;
        $query->where($fullColumn, $operator, $value);
    }

    /**
     * Apply IN filter across relation chain.
     */
    public function applyIn(
        QueryAdapter $query,
        TableContract $baseTable,
        string $chainNotation,
        array $values
    ): void {
        $parsed = $this->parser->parse($chainNotation);
        $resolved = $this->parser->resolve($baseTable, $parsed['relations'], $parsed['column']);

        // Ensure joins
        if (count($resolved['tables']) > 1) {
            $joinPath = $this->joinResolver->buildJoinGraph($resolved['tables']);
            $this->joinResolver->applyJoins($query, $joinPath);
        }

        // Apply WHERE IN
        $fullColumn = $resolved['finalTable']->table() . '.' . $parsed['column'];
        $query->whereIn($fullColumn, $values);
    }
}
```

### Component 3: Integration with QueryBuilder

**Update Slice::query() to support chain filters:**

```php
class Slice {
    protected array $chainFilters = [];

    /**
     * Add a filter on a related table.
     *
     * @param string $chainNotation e.g., 'customer.country' or 'items.product.category'
     */
    public function where(string $chainNotation, string $operator, mixed $value): static {
        $this->chainFilters[] = [
            'type' => 'where',
            'chain' => $chainNotation,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a WHERE IN filter on a related table.
     */
    public function whereIn(string $chainNotation, array $values): static {
        $this->chainFilters[] = [
            'type' => 'whereIn',
            'chain' => $chainNotation,
            'values' => $values,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT IN filter on a related table.
     */
    public function whereNotIn(string $chainNotation, array $values): static {
        $this->chainFilters[] = [
            'type' => 'whereNotIn',
            'chain' => $chainNotation,
            'values' => $values,
        ];

        return $this;
    }
}
```

**Update QueryBuilder to apply chain filters:**

```php
class QueryBuilder {
    protected RelationChainFilter $chainFilter;

    protected function buildDatabasePlan(
        array $tables,
        array $normalizedMetrics,
        array $dimensions
    ): DatabaseQueryPlan {
        $primaryTable = $tables[0];
        $query = $this->driver->createQuery($primaryTable->table());

        // Apply regular joins
        if (count($tables) > 1) {
            $joinPath = $this->joinResolver->buildJoinGraph($tables);
            $query = $this->joinResolver->applyJoins($query, $joinPath);
        }

        // Apply chain filters (new)
        foreach ($this->chainFilters as $filter) {
            match($filter['type']) {
                'where' => $this->chainFilter->apply(
                    $query,
                    $primaryTable,
                    $filter['chain'],
                    $filter['operator'],
                    $filter['value']
                ),
                'whereIn' => $this->chainFilter->applyIn(
                    $query,
                    $primaryTable,
                    $filter['chain'],
                    $filter['values']
                ),
                'whereNotIn' => $this->chainFilter->applyNotIn(
                    $query,
                    $primaryTable,
                    $filter['chain'],
                    $filter['values']
                ),
            };
        }

        // ... rest of plan building
    }
}
```

---

## Usage Examples

### Example 1: Single-Hop Filter

```php
// Filter orders by customer country
Slice::query()
    ->metrics([Sum::make('orders.total')->currency('USD')])
    ->where('customer.country', '=', 'US')
    ->get();

// Generated SQL:
// SELECT SUM(orders.total) as orders_total
// FROM orders
// JOIN customers ON orders.customer_id = customers.id
// WHERE customers.country = 'US'
```

### Example 2: Multi-Hop Filter

```php
// Filter orders by product category (through order_items)
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->where('items.product.category', '=', 'Electronics')
    ->get();

// Relation chain: orders → items → product
//
// Generated SQL:
// SELECT SUM(orders.total) as orders_total
// FROM orders
// JOIN order_items as items ON orders.id = items.order_id
// JOIN products ON items.product_id = products.id
// WHERE products.category = 'Electronics'
```

### Example 3: Multiple Chain Filters

```php
Slice::query()
    ->metrics([
        Sum::make('orders.total'),
        Count::make('orders.id'),
    ])
    ->where('customer.country', '=', 'US')
    ->where('customer.is_vip', '=', true)
    ->whereIn('items.product.category', ['Electronics', 'Computers'])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get();

// Generated SQL:
// SELECT
//     SUM(orders.total) as orders_total,
//     COUNT(orders.id) as orders_id,
//     DATE(orders.created_at) as orders_created_at_day
// FROM orders
// JOIN customers ON orders.customer_id = customers.id
// JOIN order_items as items ON orders.id = items.order_id
// JOIN products ON items.product_id = products.id
// WHERE customers.country = 'US'
//   AND customers.is_vip = 1
//   AND products.category IN ('Electronics', 'Computers')
// GROUP BY DATE(orders.created_at)
```

### Example 4: Mixed Direct and Chain Filters

```php
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->dimensions([
        Dimension::make('status')->only(['completed', 'shipped']), // Direct filter
    ])
    ->where('customer.country', '=', 'CA') // Chain filter
    ->get();

// Generated SQL:
// SELECT
//     SUM(orders.total) as orders_total,
//     orders.status
// FROM orders
// JOIN customers ON orders.customer_id = customers.id
// WHERE orders.status IN ('completed', 'shipped')
//   AND customers.country = 'CA'
// GROUP BY orders.status
```

---

## Edge Cases

### 1. Ambiguous Relation Names

**Problem:** Multiple relations with same name

```php
class Order extends Model {
    public function customer() {
        return $this->belongsTo(Customer::class);
    }

    public function billingCustomer() {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }
}
```

**Solution:** Use explicit relation name
```php
->where('billingCustomer.country', '=', 'US') // ← Use exact relation method name
```

### 2. Circular Relations

**Problem:** Model A → B → A creates cycle

**Solution:** Detect cycles, throw exception
```php
if (in_array($relatedTable->table(), array_map(fn($t) => $t->table(), $tables))) {
    throw new CircularRelationException(
        "Circular relation detected in chain: {$chainNotation}"
    );
}
```

### 3. Polymorphic Relations

**Problem:** MorphTo relations don't have fixed target

**Solution:** Not supported in MVP, require explicit join
```php
// Later: Support morph with type specification
->where('commentable(Post).title', 'LIKE', '%Laravel%')
```

### 4. HasMany Filters (Aggregation Semantics)

**Problem:** Filtering on hasMany requires EXISTS subquery

```php
// Find orders where ANY item is in Electronics category
->where('items.product.category', '=', 'Electronics')

// Should generate EXISTS, not JOIN:
// WHERE EXISTS (
//     SELECT 1 FROM order_items
//     JOIN products ON order_items.product_id = products.id
//     WHERE order_items.order_id = orders.id
//       AND products.category = 'Electronics'
// )
```

**Solution:** Detect hasMany in chain, use EXISTS instead of JOIN

```php
class RelationChainFilter {
    public function apply(...) {
        // Check if any relation in chain is hasMany
        $hasMany = $this->detectHasMany($resolved['tables']);

        if ($hasMany) {
            $this->applyExistsFilter($query, $resolved, $operator, $value);
        } else {
            $this->applyJoinFilter($query, $resolved, $operator, $value);
        }
    }

    protected function applyExistsFilter(...) {
        // Build EXISTS subquery
        $subquery = $this->driver->createQuery($firstRelatedTable->table());

        // Apply joins within subquery
        // Apply WHERE within subquery
        // Add EXISTS to main query
    }
}
```

---

## Performance Considerations

### 1. Join Deduplication

**Problem:** Multiple chain filters may require same joins

```php
->where('customer.country', '=', 'US')
->where('customer.is_vip', '=', true)
// Both need orders → customers join
```

**Solution:** JoinResolver already deduplicates via `buildJoinGraph()`

### 2. Filter Position Optimization

**Problem:** WHERE clause order affects query performance

**Best Practice:**
- Apply filters on indexed columns early
- Apply most selective filters first

**Solution:** Allow query hints (future)
```php
->where('customer.country', '=', 'US')->index('country_idx')
```

### 3. Subquery vs JOIN

**Problem:** EXISTS subqueries may be slower than JOINs

**Solution:** Make configurable
```php
// config/slice.php
'relation_filter_strategy' => 'join', // or 'exists'

// Or per-query
Slice::query()
    ->filterStrategy('exists')
    ->where('items.product.category', '=', 'Electronics')
    ->get();
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1)

**Deliverables:**
- ✅ RelationChainParser class
- ✅ Unit tests for parsing
- ✅ Resolution of single-hop chains

### Phase 2: Integration (Week 2)

**Deliverables:**
- ✅ RelationChainFilter class
- ✅ Integration with QueryBuilder
- ✅ `where()`, `whereIn()`, `whereNotIn()` methods on Slice

### Phase 3: Multi-Hop (Week 3)

**Deliverables:**
- ✅ Support for 2+ hop chains
- ✅ Auto-join application
- ✅ End-to-end tests

### Phase 4: Advanced Features (Week 4)

**Deliverables:**
- ✅ EXISTS subquery for hasMany filters
- ✅ Circular relation detection
- ✅ Performance optimization

---

## Testing Strategy

### Unit Tests

```php
describe('RelationChainParser', function () {
    it('parses single-hop chain', function () {
        $parser = new RelationChainParser();
        $result = $parser->parse('customer.country');

        expect($result)->toBe([
            'relations' => ['customer'],
            'column' => 'country',
        ]);
    });

    it('parses multi-hop chain', function () {
        $parser = new RelationChainParser();
        $result = $parser->parse('items.product.category');

        expect($result)->toBe([
            'relations' => ['items', 'product'],
            'column' => 'category',
        ]);
    });

    it('resolves chain to tables', function () {
        $parser = new RelationChainParser();
        $orderTable = EloquentTable::fromModel(Order::class);

        $resolved = $parser->resolve($orderTable, ['customer'], 'country');

        expect($resolved['tables'])->toHaveCount(2)
            ->and($resolved['finalTable']->table())->toBe('customers')
            ->and($resolved['column'])->toBe('country');
    });
});
```

### Integration Tests

```php
test('applies single-hop filter', function () {
    $results = Slice::query()
        ->metrics([Sum::make('orders.total')])
        ->where('customer.country', '=', 'US')
        ->get();

    expect($results)->not->toBeEmpty();

    // Verify SQL includes JOIN
    $sql = DB::getQueryLog()[0]['query'];
    expect($sql)->toContain('JOIN customers')
        ->and($sql)->toContain('WHERE customers.country =');
});

test('applies multi-hop filter', function () {
    $results = Slice::query()
        ->metrics([Sum::make('orders.total')])
        ->where('items.product.category', '=', 'Electronics')
        ->get();

    expect($results)->not->toBeEmpty();
});
```

---

## Backward Compatibility

**Zero breaking changes:**
- Existing Dimension filters continue to work
- New `where()` methods are additive
- Existing queries unchanged

**Migration path:**
```php
// Before: Dimension filter
->dimensions([
    Dimension::make('country')->only(['US']) // ← Works on primary table only
])

// After: Chain filter (more flexible)
->where('customer.country', '=', 'US') // ← Works on related tables
```

---

## Future Enhancements

1. **Aggregation filters:**
   ```php
   ->havingSum('items.quantity', '>', 100)
   ->havingCount('items.id', '>=', 5)
   ```

2. **Polymorphic relation chains:**
   ```php
   ->where('commentable(Post).title', 'LIKE', '%Laravel%')
   ```

3. **Nested EXISTS:**
   ```php
   ->whereHas('items.product', fn($q) =>
       $q->where('category', 'Electronics')
         ->where('price', '>', 1000)
   )
   ```

4. **Query optimization hints:**
   ```php
   ->where('customer.country', '=', 'US')->useIndex('country_idx')
   ```

---

## Conclusion

Relation chain walking enables:
- ✅ Filters across related tables without manual joins
- ✅ Multi-hop traversal (`orders` → `items` → `products`)
- ✅ Intuitive API matching Laravel Eloquent patterns
- ✅ Auto-join detection and application
- ✅ Performance optimization via EXISTS/JOIN strategies

**Implementation Effort:** 4 weeks
**Complexity:** High
**Value:** Very High (major DX improvement)
