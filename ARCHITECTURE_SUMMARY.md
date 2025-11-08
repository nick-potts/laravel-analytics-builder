# Slice Package - Architecture Summary

## Documents Generated

This analysis package contains three detailed documents:

1. **ARCHITECTURE_ANALYSIS.md** (30 KB)
   - Complete class-by-class breakdown
   - All key methods with pseudocode
   - Full data flow examples
   - Design patterns used
   - Known limitations

2. **ARCHITECTURE_DIAGRAMS.md** (24 KB)
   - Visual ASCII diagrams
   - Complete class relationships
   - Data transformation pipeline
   - Algorithm walkthroughs (BFS, DFS, joins)
   - Database-specific implementations

3. **QUICK_REFERENCE.md** (14 KB)
   - Performance bottlenecks
   - Critical decision points
   - Testing gaps
   - Refactoring roadmap
   - Glossary

**Total:** ~68 KB of architectural documentation

---

## Key Findings - TL;DR

### 1. Architecture Pattern

The system follows a **multi-stage pipeline** with **polymorphic strategy selection**:

```
Slice::query()
    ↓
INPUT NORMALIZATION (Metric/MetricEnum/string → unified format)
    ↓
PLAN GENERATION (Strategy selection based on driver, metric type, table count)
    ↓
QUERY EXECUTION (Database plan or Software join plan)
    ↓
POST-PROCESSING (Evaluate computed metrics)
    ↓
RESULT COLLECTION
```

### 2. Five Core Components

1. **Slice** - Entry point & orchestrator
2. **QueryBuilder** - Plan selection & query construction (700 lines, HIGH complexity)
3. **QueryExecutor** - Polymorphic execution (300 lines, MEDIUM-HIGH complexity)
4. **PostProcessor** - Computed metrics (270 lines, MEDIUM complexity)
5. **Registry** - Central data store (170 lines, LOW complexity)

### 3. Three Query Plans

- **DatabaseQueryPlan**: Single database query (Thin wrapper)
- **SoftwareJoinQueryPlan**: Multiple queries + PHP joins (Rich metadata)
- **DatabasePlan + CTEs**: Layered computed metrics (SQL Server compatible)

### 4. Multi-Database Support

7 database drivers supported out of the box:
- MySQL, PostgreSQL, SQLite, SQL Server, MariaDB, SingleStore, Firebird

Each has custom **QueryGrammar** for database-specific SQL generation (especially time bucketing).

### 5. Two Metric Types

- **Aggregation Metrics**: Sum, Count, Avg, Min, Max (computed in SQL)
- **Computed Metrics**: Depend on other metrics (computed post-execution)

### 6. Join Resolution

Uses **BFS (Breadth-First Search)** to find shortest path between tables based on FK relationships.

### 7. Dependency Tracking

Uses **DFS (Depth-First Search)** topological sort to:
- Order metrics by dependencies
- Group by computation level (for CTEs)
- Classify as database vs software computed

---

## Critical Insights for Refactoring

### Strength #1: Clean Separation of Concerns
Each stage (normalize → build → execute → post-process) is independent and testable.

**Impact:** Can refactor one stage without breaking others.

### Strength #2: Polymorphic Input
Supports Metric instances, MetricEnum cases, and strings through normalization.

**Impact:** Flexible API with type-safety and discoverable strings.

### Strength #3: Plan-Based Execution
Query plans encapsulate all metadata needed for execution.

**Impact:** Can defer strategy selection to build time, enabling smart plan optimization.

### Strength #4: Adapter Pattern
QueryDriver and QueryAdapter abstract database details.

**Impact:** Easy to add new databases (just implement Grammar).

### Weakness #1: No Caching
DependencyResolver, JoinResolver, and DimensionResolver are called repeatedly on same data.

**Impact:** Performance regression on complex queries. Fix: Add caching layer.

### Weakness #2: Software Join Limitations
Only supports INNER joins and requires all rows in memory.

**Impact:** Can't use for very large result sets or when LEFT join needed. Fix: Improve algorithm.

### Weakness #3: Computed Metric Evaluation
Cross-table computed metrics evaluated row-by-row in PHP.

**Impact:** Semantically incorrect for aggregations. Example: `revenue/count` won't work correctly.

### Weakness #4: Registry Coupling
String-based metrics require boot-time enum discovery.

**Impact:** Can't dynamically register metrics. Can't use without Registry.

### Weakness #5: Expression Evaluation
Uses eval() as fallback for math expression parsing.

**Impact:** Security risk if expressions come from untrusted input.

---

## Key Architectural Questions

### Q1: Why Enums at All?
**A:** Type safety + IDE autocomplete. Alternative: Use string constants class.

### Q2: Why Store Table Instance vs String Name?
**A:** Current design assumes table() method. Alternative: Use registry string lookups.

### Q3: Why Split Metrics in Normalization?
**A:** To support 3 input types polymorphically. Could use visitor pattern instead.

### Q4: Why Software Joins Instead of Always CTEs?
**A:** Not all databases support CTEs. Alternative: Check driver capability first.

### Q5: Why Evaluate Computed Metrics Post-Execution?
**A:** Some metrics cross tables, can't express in SQL. Could: Materialize cross-table joins.

---

## Files Most Likely to Change in Refactor

### High Risk (Complex, many responsibilities)
1. `src/Engine/QueryBuilder.php` (700 lines)
   - Contains plan selection logic
   - Dimension resolution
   - Query construction
   - Consider: Split into smaller classes

2. `src/Engine/QueryExecutor.php` (300 lines)
   - Software join algorithm
   - Grouping logic
   - Consider: Extract soft join to separate class

### Medium Risk (Central to system)
3. `src/Support/Registry.php` (170 lines)
   - Auto-discovery mechanism
   - Lookup logic
   - Consider: Make configurable, support direct metrics

4. `src/Engine/PostProcessor.php` (270 lines)
   - Expression evaluation
   - Uses eval() fallback
   - Consider: Replace with safe parser

### Low Risk (Well-isolated)
5. `src/Engine/DimensionResolver.php` (87 lines)
   - Simple mapping logic
   - Well-defined interface

6. `src/Engine/JoinResolver.php` (133 lines)
   - BFS algorithm
   - Could add caching

7. `src/Engine/DependencyResolver.php` (240 lines)
   - DFS topological sort
   - Could add caching

---

## Estimated Complexity by Refactor Task

| Task | Files | LOC | Complexity | Risk |
|------|-------|-----|-----------|------|
| Add caching to resolvers | 3 | 100 | LOW | LOW |
| Extract soft join algorithm | 1 | 200 | MEDIUM | MEDIUM |
| Replace eval() with safe parser | 1 | 150 | MEDIUM | MEDIUM |
| Add LEFT join support | 1 | 100 | MEDIUM | MEDIUM |
| Split QueryBuilder | 1 | 200 | HIGH | HIGH |
| Make Registry configurable | 2 | 80 | LOW | MEDIUM |
| Add computed metric validation | 2 | 60 | LOW | LOW |
| Support direct metrics in Registry | 1 | 40 | LOW | LOW |
| Optimize soft join algorithm | 1 | 150 | MEDIUM | MEDIUM |
| Add query plan caching | 2 | 80 | LOW | LOW |

**Total:** 1,160 additional lines for 10 major improvements

---

## Hooks for Extending

The architecture provides several extension points without modifying core code:

### 1. Custom Metrics
```php
class Percentile implements DatabaseMetric {
    public function applyToQuery(QueryAdapter $adapter, QueryDriver $driver, ...) { }
}
```

### 2. Custom Dimensions
```php
class GeoHashDimension extends Dimension { }
```

### 3. Custom Database Drivers
```php
class ClickhouseGrammar extends QueryGrammar {
    public function formatTimeBucket(...) { }
}

LaravelQueryDriver::extend('clickhouse', ClickhouseGrammar::class);
```

### 4. Custom Query Adapters
```php
class CustomQueryAdapter implements QueryAdapter { }
```

### 5. Custom Query Plans
```php
class MaterializedViewQueryPlan implements QueryPlan { }
```

---

## Testing Strategy Based on Architecture

### Unit Tests (Each component in isolation)
- DimensionResolver - Mapping logic
- JoinResolver - BFS pathfinding
- DependencyResolver - Topological sort
- Registry - Lookup and storage

### Integration Tests (Component combinations)
- Normalization → Plan generation
- Plan generation → Execution
- Execution → Post-processing

### Feature Tests (End-to-end flows)
- Single-table queries
- Multi-table queries
- Computed metrics
- Software joins
- CTE generation

### Performance Tests
- Large result sets
- Deep dependency graphs
- Complex join paths

### Database-Specific Tests
- Time bucketing by driver
- CTE support detection
- Join capability detection

---

## Implementation Order Recommendation

If starting a major refactor:

1. **Stabilize first** (Week 1)
   - Add comprehensive unit tests
   - Document current behavior
   - Fix any obvious bugs

2. **Cache strategically** (Week 2)
   - Add caching to DependencyResolver
   - Add caching to JoinResolver
   - Add caching to DimensionResolver
   - Measure performance improvement

3. **Improve safety** (Week 3)
   - Replace eval() with safe math parser
   - Add expression validation
   - Add error messages

4. **Enhance features** (Week 4+)
   - Add LEFT/RIGHT join support
   - Improve computed metrics validation
   - Support direct metrics in Registry
   - Add query plan caching

5. **Optimize execution** (Week 5+)
   - Optimize soft join algorithm
   - Add result caching
   - Profile and optimize hot paths

---

## Success Metrics for Refactor

1. **Code Health**
   - Increase test coverage to 80%+
   - Reduce class complexity (max 10 methods per class)
   - Document all public APIs

2. **Performance**
   - 20%+ improvement on complex queries (via caching)
   - 50%+ improvement on soft joins (better algorithm)
   - Support 100K+ row results (current limit unknown)

3. **Features**
   - Support computed metrics on cross-table data
   - Add LEFT/RIGHT join support
   - Add more aggregation functions (percentile, median, mode)
   - Support custom drivers via plugin system

4. **DX (Developer Experience)**
   - Clearer error messages
   - Better documentation
   - Examples for common patterns
   - Type hints throughout

---

## References to Original Documentation

See `/home/user/laravel-analytics-builder/CLAUDE.md` for:
- Table-centric design philosophy
- Metric enum pattern
- Dimension-based grouping
- Multi-database support details

---

## End of Summary

For detailed information, refer to:
- **ARCHITECTURE_ANALYSIS.md** - Deep dive into each class
- **ARCHITECTURE_DIAGRAMS.md** - Visual representations
- **QUICK_REFERENCE.md** - Practical refactoring guide

Generated: 2025-11-08
Total Analysis: ~68 KB, 9 comprehensive documents
