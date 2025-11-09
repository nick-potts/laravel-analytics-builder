# Slice Roadmap: Cube.js-Inspired Semantic Layer for Laravel

**Vision:** Build the most developer-friendly analytics layer for Laravel, inspired by cube.js's semantic layer approach but optimized for Laravel's ecosystem.

**Timeline:** Q4 2025 → Q1 2026

---

## Current Status: Phase 1-3 Complete ✅

### What's Done
- ✅ **Auto-Discovery** - EloquentSchemaProvider introspects models automatically
- ✅ **Auto-Joins** - JoinResolver finds transitive relationship paths
- ✅ **Basic Aggregations** - Sum, Count, Avg with driver-specific SQL

### Test Coverage
- 50+ unit tests across all components
- Feature tests passing on SQLite, MySQL, MariaDB, PostgreSQL
- 100% contract coverage for SchemaProvider interface

---

## Phase 4: Schema Pre-Computation & Auto-Aggregations (Week 6)

### Goals
1. **Pre-compute entire schema at bootstrap** (zero runtime overhead)
2. Smart GROUP BY resolution with heuristics
3. Support calculated/composed measures

### Key Features

#### 4.1 Pre-Computed Schema (Foundation)
- **Problem:** Every query re-computes tables, relations, dimensions, metrics
- **Solution:** Build complete schema once at app boot, reuse forever

```php
// At bootstrap (ServiceProvider)
$schema = app(SchemaProviderManager::class)->buildCompleteSchema();
// Returns: all tables + relations + dimensions + metrics resolved

app()->singleton('slice.schema', $schema);

// Every query uses pre-built schema (instant lookups)
$builder = Slice::query();
$builder->useSchema(app('slice.schema')); // No re-parsing, no re-discovery
```

**Benefits:**
- ✅ Zero redundant work across multiple queries
- ✅ Lightning-fast metric/dimension/relation lookups
- ✅ Deterministic performance (no surprise latency)
- ✅ Still fresh per-request (recomputed at app boot, not file-cached)

**Implementation:**
- Add `SchemaProvider::buildCompleteSchema(): CompiledSchema` method
- `CompiledSchema` object holds:
  - All SliceSources (tables)
  - All RelationGraphs pre-computed
  - All DimensionCatalogs pre-computed
  - Pre-parsed metrics catalog
- Bootstrap singleton in service provider
- Refactor QueryBuilder to use pre-built schema instead of runtime resolution

#### 4.2 BaseTableResolver
- **Problem:** Given metrics `Sum('orders.total')` and `Count('invoices.id')`, which table is the primary?
- **Solution:** Intelligent heuristics using pre-computed schema
  - Most metrics originated from same table → that table
  - Conflict detection with helpful error messages
  - Allow explicit `baseTable()` override

#### 4.3 Calculated Measures
- **Example:** `ArpuMeasure = Sum('orders.total') / Count('customers.id')`
- **Implementation:**
  - Compose existing aggregations into formulas
  - Auto-generate required GROUP BY structure using pre-computed schema
  - Handle NULL safely (division by zero, empty results)

```php
// Example usage (Phase 4)
Slice::query()
    ->metrics([
        Sum::make('orders.total')->alias('revenue'),
        Count::make('customers.id')->alias('customer_count'),
        Calculated::make('arpu') // revenue / customer_count
            ->formula(fn($revenue, $customerCount) => $revenue / $customerCount)
    ])
    ->dimensions([TimeDimension::make('orders.created_at')->monthly()])
    ->get();
```

#### 4.4 Intelligent Grouping
- Automatic GROUP BY based on:
  - Requested dimensions
  - Metric dependencies (from pre-computed schema)
  - Join structure (from pre-computed relations)
- Edge case handling (NULL grouping, duplicate dimensions)

### Deliverables
- `CompiledSchema` value object
- `SchemaProvider::buildCompleteSchema()` implementation
- Refactored `QueryBuilder` to use pre-built schema
- `BaseTableResolver` class with heuristics
- `Calculated` aggregation class
- 20+ unit tests for edge cases
- Documentation with performance benchmarks

---

## Phase 5: Views Layer & Relation Filters (Week 7)

### Goals
- Semantic facade layer (cube.js-style Views)
- Multi-hop relation filtering
- Ambiguous path resolution

### Key Features

#### 5.1 Views (Semantic Layer Facade)
- **Problem:** Some queries use complex multi-table logic (e.g., "Orders with customer details")
- **Solution:** Define reusable Views on top of base SliceSources

```php
// Define a View
class OrdersWithCustomerView implements View {
    public function baseTable(): string {
        return 'orders';
    }

    public function joins(): array {
        return [
            'customers' => 'orders.customer_id = customers.id',
        ];
    }

    public function availableDimensions(): array {
        return [
            'order_id' => 'orders.id',
            'customer_name' => 'customers.name',
            'order_date' => 'orders.created_at',
        ];
    }
}

// Use it (avoids complex join specs)
Slice::query(OrdersWithCustomerView::class)
    ->metrics([Sum::make('total')])
    ->dimensions(['customer_name', 'order_date'])
    ->get();
```

#### 5.2 Multi-Hop Relation Filtering
- **Problem:** Filter by deeply nested relations (e.g., "Orders where customer.country = 'US'")
- **Solution:** Walk relation chains automatically

```php
// Example: Orders where invoice.account.status = 'active'
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->where(['invoices.account.status' => 'active']) // 2-hop filter
    ->get();
```

#### 5.3 Ambiguous Path Resolution
- **Problem:** Two paths exist between same tables (diamonds in relation graph)
- **Solution:** Hints + preferences

```php
// When ambiguity detected, use hints
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->joinPreference('orders.customer_id → customers.id')
    ->get();

// Or register preference in View
class OrdersView implements View {
    public function joinPreferences(): array {
        return [
            'customers' => 'orders.customer_id',
        ];
    }
}
```

### Deliverables
- `View` interface + base class
- `RelationPathWalker` for multi-hop filters
- `AmbiguityResolver` with hints
- Example Views for common patterns
- 20+ tests for complex scenarios

---

## Phase 6: Pre-Aggregations & Documentation (Week 8)

### Goals
- Performance optimization via pre-aggregations
- Complete all developer documentation
- Support custom provider ecosystem

### Key Features

#### 6.1 Pre-Aggregations (Cube.js Style)
- **Problem:** Repeated queries (e.g., daily revenue) are slow
- **Solution:** Cache pre-computed aggregates

```php
// Define a pre-agg
class DailyRevenuePreAgg extends PreAggregation {
    public function measures(): array {
        return [Sum::make('orders.total')];
    }

    public function dimensions(): array {
        return [TimeDimension::make('orders.created_at')->daily()];
    }

    public function refreshInterval(): string {
        return 'daily'; // Refresh once per day at 3 AM
    }
}

// Auto-uses cache when available
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get(); // Hits pre-agg table instead of base tables
```

#### 6.2 Provider Author Guide
- How to build custom SchemaProviders
- Integration testing for providers
- Publishing guidelines for community

#### 6.3 Example Providers
- **ClickHouse** - Analytics database
- **OpenAPI** - REST APIs
- **GraphQL** - GraphQL endpoints
- **PostgreSQL** - Direct DB introspection

#### 6.4 Migration & API Docs
- **Upgrade Guide:** Legacy Table classes → Providers
- **API Reference:** All public methods
- **Tutorial:** Build a custom provider in 15 minutes

### Deliverables
- `PreAggregation` base class
- Pre-agg manager (caching, refresh scheduling)
- Provider author documentation (5000+ words)
- 3 example providers (working code)
- API reference (auto-generated from code)
- Video tutorial series (5-10 min each)

---

## Future Enhancements (Post-Phase 6)

### Segments (Reusable Filters)
```php
// Define common filters
class ActiveCustomersSegment implements Segment {
    public function definition(): string {
        return 'customers.status = "active"';
    }
}

// Use them everywhere
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->apply(ActiveCustomersSegment::class)
    ->get();
```

### Data Blending (Multi-Source Queries)
- **Goal:** Combine data from Eloquent + ClickHouse in single query
- **Challenge:** Cross-connection joins aren't possible in SQL
- **Solution:** Software joins in PHP (map-reduce style)

```php
// Future: Mix different data sources
Slice::query()
    ->metrics([
        Sum::make('orders.total'),           // Eloquent
        Sum::make('clickhouse:events.count'), // ClickHouse
    ])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get();
```

### LLM-Friendly API
- **Goal:** Make queries composable for AI/ChatGPT
- **Implementation:** Structured metadata about available metrics/dimensions
- **Benefit:** LLMs can generate analytics queries with low hallucination rate

```php
// Expose schema to LLMs
$schema = Slice::schema(); // Returns { cubes: [...], measures: [...], dimensions: [...] }
// Pass to Claude/ChatGPT context window
```

### Query Caching & Materialization
- Cache final query results (not just pre-aggs)
- Stale-while-revalidate strategy
- Configurable TTL per metric

---

## Success Metrics

### Development
- [x] Phase 1-3: 2 hours (massively ahead!)
- [ ] Phase 4: 7 days (schema pre-computation + auto-aggregations)
  - Schema pre-computation: ~3 days (foundation, all subsequent features depend on this)
  - BaseTableResolver + Calculated measures: ~4 days
- [ ] Phase 5: 5 days (Views + multi-hop)
- [ ] Phase 6: 5 days (docs + examples)
- **Total:** ~17 days (3.5 weeks with buffer)

### Quality
- [ ] 100% test coverage (unit + feature)
- [ ] PHPStan level 9
- [ ] Zero breaking changes
- [ ] < 50ms query planning overhead

### Community
- [ ] 5+ community providers by end of Q1
- [ ] 50+ GitHub stars
- [ ] Active provider discussions
- [ ] Conference talk(s)

---

## Architecture Decisions Going Forward

### 1. Laravel-First Terminology
- Keep **SliceSource**, not "Cube"
- Keep **Aggregations**, not "Measures"
- Add **Dimensions**, **Views** (cube.js concepts that fit)
- Why: Laravel familiarity matters more than perfect alignment

### 2. Type Safety Over Flexibility
- PHP 8.1 enums for metric definitions
- Typed value objects throughout
- Compile-time safety where possible
- Why: Laravel community values type safety (Livewire, Folio, etc.)

### 3. Convention Over Configuration
- Auto-discover everything possible
- Sensible defaults (single table = primary table)
- Override only when necessary
- Why: 90% of queries are simple; let experts configure complex ones

### 4. Performance Budgets
- Schema resolution: < 50ms (production, cached)
- Query planning: < 20ms
- Single-table query: < 100ms total
- Why: Used in real-time dashboards, must be snappy

---

## References

- **CURRENT_ARCHITECTURE.md** - What exists now (Phase 1-3)
- **API_GUIDE.md** - How to use Slice
- **REFACTOR_EXECUTIVE_SUMMARY.md** - Vision & rationale
- **SCHEMA_PROVIDER_REFACTOR.md** - Detailed architecture spec

---

**Last Updated:** 2025-11-09
**Roadmap Confidence:** High (based on Phase 1-3 success)
**Next Milestone:** Phase 4 kickoff (Day 1 of Week 6)
