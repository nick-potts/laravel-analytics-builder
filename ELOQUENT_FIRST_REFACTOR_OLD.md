# Eloquent-First Architecture Refactor - Comprehensive Plan

**Document Version:** 1.0
**Date:** 2025-11-08
**Status:** Design Phase

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Current State Analysis](#current-state-analysis)
3. [Architecture Design](#architecture-design)
4. [Implementation Phases](#implementation-phases)
5. [API Design](#api-design)
6. [Edge Case Handling](#edge-case-handling)
7. [Testing Strategy](#testing-strategy)
8. [Performance Analysis](#performance-analysis)
9. [Migration Guide](#migration-guide)
10. [Risk Assessment](#risk-assessment)

---

## Executive Summary

### Vision

Transform Slice from an **explicit Table-centric architecture** to an **Eloquent-first architecture** where:
- Developers use `Sum::make('orders.total')` without defining Table classes
- Eloquent models are automatically introspected for relations, casts, and structure
- Table classes remain optional for non-Eloquent data sources (APIs, Clickhouse, etc.)
- Zero breaking changes for existing users

### Key Metrics

| Metric | Current | Target | Improvement |
|--------|---------|--------|-------------|
| **Lines to add metric** | 50+ (Table + Enum) | 1 (direct aggregation) | 98% reduction |
| **Developer friction** | High (manual relations) | Low (auto-introspection) | Massive |
| **Model discovery** | Manual registration | Auto-scan models | Seamless |
| **Backward compatibility** | N/A | 100% | No breaking changes |

### Success Criteria

1. ✅ **Zero breaking changes** - All existing Table classes work unchanged
2. ✅ **Auto-introspection** - Eloquent models discovered without configuration
3. ✅ **Performance** - < 50ms introspection overhead in production (cached)
4. ✅ **Type safety** - Maintain current enum-based type safety option
5. ✅ **Multi-database** - Support all 7 existing database drivers

---

## Current State Analysis

### Architecture Overview

```
Current Flow:
User defines Table class → Manual relations → Manual dimensions → Metric enum references table
    ↓
Slice::query() → normalizeMetrics() → QueryBuilder.build() → JoinResolver → SQL
```

### Key Dependencies on Table Classes

**Where Table classes are used:**

1. **Metric Definition** - `MetricContract::table()` returns Table instance
2. **Relation Discovery** - `Table::relations()` for join resolution
3. **Dimension Mapping** - `Table::dimensions()` for column resolution
4. **Registry** - `Registry::registerMetricEnum()` stores Table instances

**Current Pain Points:**

```php
// Current: Must define OrdersTable
class OrdersTable extends Table {
    protected string $table = 'orders';

    public function dimensions(): array {
        return [
            TimeDimension::class => TimeDimension::make('created_at'),
        ];
    }

    public function relations(): array {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id'),
        ];
    }
}

// Current: Must define enum
enum OrdersMetric: string implements MetricContract {
    case Revenue = 'revenue';

    public function table(): Table {
        return new OrdersTable(); // ← Manual instantiation
    }

    public function get(): MetricContract {
        return Sum::make('orders.total')->currency('USD');
    }
}

// Usage
Slice::query()
    ->metrics([OrdersMetric::Revenue]) // ← Requires enum
    ->get();
```

**Desired Future State:**

```php
// Future: Order model (standard Eloquent)
namespace App\Models;

class Order extends Model {
    protected $casts = [
        'created_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function customer() {
        return $this->belongsTo(Customer::class);
    }

    public function items() {
        return $this->hasMany(OrderItem::class);
    }
}

// Usage: Direct aggregation, no Table or Enum needed
Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD'), // ← Auto-detects Order model
        Count::make('orders.id'),
    ])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get();
```

---

## Architecture Design

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         SLICE QUERY API                         │
│  Slice::query()->metrics([...])->dimensions([...])->get()      │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                    METRIC NORMALIZATION                         │
│  Accepts: MetricEnum | Metric | string                         │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ NEW: TableResolver                                       │  │
│  │ - parseTableColumn('orders.total')                       │  │
│  │ - resolveTable('orders') → EloquentTable OR ManualTable  │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                      TABLE ABSTRACTION                          │
│  ┌────────────────────┐         ┌──────────────────────┐       │
│  │  TableContract     │         │  NEW: ModelRegistry  │       │
│  │  (interface)       │         │  - scanModels()      │       │
│  ├────────────────────┤         │  - cacheMetadata()   │       │
│  │ table(): string    │         │  - lookup(table)     │       │
│  │ relations()        │         └──────────────────────┘       │
│  │ dimensions()       │                                         │
│  └────────────────────┘                                         │
│           △                                                     │
│           │                                                     │
│  ┌────────┴──────────────┐                                     │
│  │                       │                                     │
│  │                       │                                     │
│ ┌▼────────────────┐ ┌───▼──────────────┐                      │
│ │ ManualTable     │ │ EloquentTable    │ ← NEW                │
│ │ (existing)      │ │ (auto-generated) │                      │
│ │                 │ │                  │                      │
│ │ Explicit        │ │ Introspection:   │                      │
│ │ relations()     │ │ - Extract from   │                      │
│ │ dimensions()    │ │   Eloquent model │                      │
│ └─────────────────┘ │ - Casts → dims   │                      │
│                     │ - Relations      │                      │
│                     └──────────────────┘                      │
└─────────────────────────────────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                    EXISTING QUERY ENGINE                        │
│  QueryBuilder → JoinResolver → QueryExecutor → Results         │
│  (Unchanged - works with TableContract interface)              │
└─────────────────────────────────────────────────────────────────┘
```

### New Components

#### 1. TableContract Interface

**Purpose:** Abstract Table classes to support multiple implementations

```php
interface TableContract {
    /**
     * Get the database table name.
     */
    public function table(): string;

    /**
     * Get dimension definitions.
     * @return array<class-string<Dimension>|string, Dimension>
     */
    public function dimensions(): array;

    /**
     * Get relation definitions.
     * @return array<string, Relation>
     */
    public function relations(): array;

    /**
     * Get cross-join definitions.
     * @return array<string, CrossJoin>
     */
    public function crossJoins(): array;

    /**
     * Get source type: 'eloquent', 'manual', 'api', etc.
     */
    public function sourceType(): string;

    /**
     * Get underlying model class (if Eloquent).
     */
    public function modelClass(): ?string;
}
```

#### 2. EloquentTable (Adapter Pattern)

**Purpose:** Auto-generate Table from Eloquent model via introspection

```php
class EloquentTable implements TableContract {
    protected string $modelClass;
    protected string $tableName;
    protected ?array $cachedRelations = null;
    protected ?array $cachedDimensions = null;

    public function __construct(string $modelClass) {
        $this->modelClass = $modelClass;

        // Extract table name from model
        $model = new $modelClass;
        $this->tableName = $model->getTable();
    }

    public function table(): string {
        return $this->tableName;
    }

    public function relations(): array {
        if ($this->cachedRelations !== null) {
            return $this->cachedRelations;
        }

        $this->cachedRelations = $this->introspectRelations();
        return $this->cachedRelations;
    }

    public function dimensions(): array {
        if ($this->cachedDimensions !== null) {
            return $this->cachedDimensions;
        }

        $this->cachedDimensions = $this->introspectDimensions();
        return $this->cachedDimensions;
    }

    public function sourceType(): string {
        return 'eloquent';
    }

    public function modelClass(): ?string {
        return $this->modelClass;
    }

    /**
     * Introspect Eloquent model relations using Reflection.
     */
    protected function introspectRelations(): array {
        $relations = [];
        $reflector = new ReflectionClass($this->modelClass);

        foreach ($reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited methods from Model
            if ($method->class === Model::class || $method->class === 'Illuminate\\Database\\Eloquent\\Model') {
                continue;
            }

            // Check if method returns a Relation instance
            $returnType = $method->getReturnType();
            if (!$returnType) {
                continue;
            }

            $returnTypeName = $returnType instanceof ReflectionNamedType
                ? $returnType->getName()
                : null;

            // Match Eloquent relation return types
            if ($this->isEloquentRelation($returnTypeName)) {
                $relationName = $method->getName();
                $sliceRelation = $this->convertEloquentRelation($relationName, $method);

                if ($sliceRelation) {
                    $relations[$relationName] = $sliceRelation;
                }
            }
        }

        return $relations;
    }

    /**
     * Introspect model casts to auto-detect dimension support.
     */
    protected function introspectDimensions(): array {
        $dimensions = [];
        $model = new $this->modelClass;
        $casts = $model->getCasts();

        foreach ($casts as $column => $castType) {
            // datetime/date casts → TimeDimension
            if (in_array($castType, ['datetime', 'date', 'timestamp', 'immutable_datetime'])) {
                $precision = in_array($castType, ['date']) ? 'date' : 'timestamp';
                $dimensions[TimeDimension::class."::$column"] = TimeDimension::make($column)
                    ->precision($precision);
            }

            // enum casts → Could support EnumDimension (future)
            // string/integer casts → Could be dimensions if configured
        }

        return $dimensions;
    }

    /**
     * Convert Eloquent relation to Slice relation.
     */
    protected function convertEloquentRelation(string $name, ReflectionMethod $method): ?Relation {
        $model = new $this->modelClass;

        try {
            $eloquentRelation = $method->invoke($model);
        } catch (Throwable $e) {
            // Relation method requires parameters or has side effects
            return null;
        }

        // Get related model class
        $relatedModel = get_class($eloquentRelation->getRelated());
        $relatedTable = EloquentTable::fromModel($relatedModel);

        // Convert to Slice relation types
        if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            return new BelongsTo(
                get_class($relatedTable),
                $eloquentRelation->getForeignKeyName(),
                $eloquentRelation->getOwnerKeyName()
            );
        }

        if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
            return new HasMany(
                get_class($relatedTable),
                $eloquentRelation->getForeignKeyName(),
                $eloquentRelation->getLocalKeyName()
            );
        }

        if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            return new BelongsToMany(
                get_class($relatedTable),
                $eloquentRelation->getTable(), // Pivot table
                $eloquentRelation->getForeignPivotKeyName(),
                $eloquentRelation->getRelatedPivotKeyName()
            );
        }

        // HasOne, MorphTo, MorphMany, etc. - can be added incrementally

        return null;
    }

    /**
     * Static factory method.
     */
    public static function fromModel(string $modelClass): static {
        return new static($modelClass);
    }
}
```

#### 3. ModelRegistry

**Purpose:** Scan and cache Eloquent models for fast lookup

```php
class ModelRegistry {
    /** @var array<string, string> table_name => ModelClass */
    protected array $tableToModel = [];

    /** @var array<string, EloquentTable> table_name => EloquentTable */
    protected array $eloquentTables = [];

    /** @var bool */
    protected bool $scanned = false;

    /** @var array<string> Directories to scan for models */
    protected array $modelPaths = [];

    public function __construct(array $modelPaths = null) {
        $this->modelPaths = $modelPaths ?? [app_path('Models')];
    }

    /**
     * Scan all model directories and build registry.
     */
    public function scan(): void {
        if ($this->scanned) {
            return;
        }

        foreach ($this->modelPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $this->scanDirectory($path);
        }

        $this->scanned = true;
    }

    /**
     * Recursively scan directory for Eloquent models.
     */
    protected function scanDirectory(string $directory, string $namespace = 'App\\Models'): void {
        $files = glob($directory . '/*.php');

        foreach ($files as $file) {
            $className = $namespace . '\\' . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            // Check if it's an Eloquent model
            if ($reflection->isSubclassOf(Model::class) && !$reflection->isAbstract()) {
                $this->registerModel($className);
            }
        }

        // Scan subdirectories
        $subdirs = glob($directory . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $subdirName = basename($subdir);
            $this->scanDirectory($subdir, $namespace . '\\' . $subdirName);
        }
    }

    /**
     * Register a single model.
     */
    public function registerModel(string $modelClass): void {
        $model = new $modelClass;
        $tableName = $model->getTable();

        $this->tableToModel[$tableName] = $modelClass;
        $this->eloquentTables[$tableName] = EloquentTable::fromModel($modelClass);
    }

    /**
     * Lookup table by name - returns EloquentTable or null.
     */
    public function lookup(string $tableName): ?TableContract {
        $this->scan(); // Lazy scan on first lookup

        return $this->eloquentTables[$tableName] ?? null;
    }

    /**
     * Check if a table has an Eloquent model.
     */
    public function hasModel(string $tableName): bool {
        $this->scan();

        return isset($this->tableToModel[$tableName]);
    }

    /**
     * Get model class for table name.
     */
    public function getModelClass(string $tableName): ?string {
        $this->scan();

        return $this->tableToModel[$tableName] ?? null;
    }

    /**
     * Cache registry to file for production.
     */
    public function cache(string $path): void {
        $this->scan();

        $data = [
            'tableToModel' => $this->tableToModel,
            'scanned_at' => now()->toIso8601String(),
        ];

        file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    }

    /**
     * Load registry from cache.
     */
    public function loadCache(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        $data = require $path;
        $this->tableToModel = $data['tableToModel'];

        // Rebuild EloquentTable instances
        foreach ($this->tableToModel as $tableName => $modelClass) {
            $this->eloquentTables[$tableName] = EloquentTable::fromModel($modelClass);
        }

        $this->scanned = true;
    }
}
```

#### 4. TableResolver

**Purpose:** Resolve table.column notation to TableContract instances

```php
class TableResolver {
    protected ModelRegistry $modelRegistry;
    protected Registry $manualRegistry;

    public function __construct(ModelRegistry $modelRegistry, Registry $manualRegistry) {
        $this->modelRegistry = $modelRegistry;
        $this->manualRegistry = $manualRegistry;
    }

    /**
     * Parse 'table.column' notation.
     *
     * @return array{table: string, column: string}
     */
    public function parseTableColumn(string $notation): array {
        $parts = explode('.', $notation, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException(
                "Invalid table.column notation: {$notation}. Expected format: 'table_name.column_name'"
            );
        }

        return [
            'table' => $parts[0],
            'column' => $parts[1],
        ];
    }

    /**
     * Resolve table name to TableContract instance.
     *
     * Priority:
     * 1. Manual Table class (from existing Registry)
     * 2. Eloquent model (from ModelRegistry)
     * 3. Throw exception
     */
    public function resolveTable(string $tableName): TableContract {
        // Check manual registry first (backward compatibility)
        $manualTable = $this->manualRegistry->getTable($tableName);
        if ($manualTable) {
            return $manualTable;
        }

        // Check Eloquent models
        $eloquentTable = $this->modelRegistry->lookup($tableName);
        if ($eloquentTable) {
            return $eloquentTable;
        }

        throw new InvalidArgumentException(
            "Table '{$tableName}' not found. ".
            "Please ensure you have an Eloquent model with table name '{$tableName}' ".
            "or define a manual Table class."
        );
    }
}
```

### Data Flow Transformation

**Before (Current):**

```
Sum::make('orders.total')
  ↓
Aggregation::__construct('orders.total')
  ↓
extractTableAndColumn() → ['orders', 'total']
  ↓
Registry::getTableByName('orders') → OrdersTable instance
  ↓
MetricContract::table() returns OrdersTable
```

**After (New):**

```
Sum::make('orders.total')
  ↓
Aggregation::__construct('orders.total')
  ↓
TableResolver::parseTableColumn('orders.total') → ['table' => 'orders', 'column' => 'total']
  ↓
TableResolver::resolveTable('orders')
  ├─ Check Registry::getTable('orders') → Manual OrdersTable? (backward compat)
  │  └─ If found: return ManualTable
  ├─ Check ModelRegistry::lookup('orders') → Eloquent Order model?
  │  └─ If found: return EloquentTable::fromModel(Order::class)
  └─ Not found: throw exception
  ↓
MetricContract::table() returns TableContract (Manual OR Eloquent)
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)

**Goal:** Create new abstractions without breaking existing code

**Tasks:**

1. **Create TableContract interface** (2 days)
   - Define interface with all methods from Table class
   - Add new methods: `sourceType()`, `modelClass()`
   - Write comprehensive interface documentation

2. **Update Table class to implement TableContract** (1 day)
   - Add `implements TableContract` to existing Table class
   - Implement new methods with default values
   - Run existing tests to ensure no breakage

3. **Create EloquentTable class** (3 days)
   - Implement full TableContract
   - Write introspection logic for relations (BelongsTo, HasMany, BelongsToMany)
   - Write introspection logic for casts → dimensions
   - Add comprehensive error handling
   - Unit tests for introspection logic

4. **Create ModelRegistry** (2 days)
   - Implement directory scanning
   - Implement model registration
   - Add caching mechanism
   - Unit tests for scanning and lookup

5. **Create TableResolver** (1 day)
   - Implement parseTableColumn()
   - Implement resolveTable() with fallback priority
   - Unit tests for resolution logic

**Deliverables:**
- ✅ All new classes with 100% test coverage
- ✅ Existing tests still pass
- ✅ Zero breaking changes

**Testing:**
```php
// Test EloquentTable introspection
test('EloquentTable introspects BelongsTo relations', function () {
    $table = EloquentTable::fromModel(Order::class);
    $relations = $table->relations();

    expect($relations)->toHaveKey('customer')
        ->and($relations['customer'])->toBeInstanceOf(BelongsTo::class);
});

// Test ModelRegistry scanning
test('ModelRegistry scans and registers all models', function () {
    $registry = new ModelRegistry([app_path('Models')]);
    $registry->scan();

    expect($registry->hasModel('orders'))->toBeTrue()
        ->and($registry->getModelClass('orders'))->toBe(Order::class);
});
```

---

### Phase 2: Integration (Week 3)

**Goal:** Integrate new components into existing flow

**Tasks:**

1. **Update Aggregation base class** (2 days)
   - Inject TableResolver instead of Registry
   - Update `table()` method to use TableResolver
   - Maintain backward compatibility with existing metrics
   - Tests for both manual and Eloquent table resolution

2. **Update Registry to support both types** (1 day)
   - Store both ManualTable and EloquentTable instances
   - Update lookup methods to work with TableContract
   - Tests for mixed usage

3. **Update Slice service provider** (1 day)
   - Register ModelRegistry as singleton
   - Register TableResolver as singleton
   - Auto-scan models on boot (only in debug mode)
   - Load cached registry in production

4. **Update all engine components** (1 day)
   - Update QueryBuilder to accept TableContract
   - Update JoinResolver to work with TableContract
   - Update DimensionResolver to work with TableContract
   - Tests to ensure both table types work

**Deliverables:**
- ✅ Aggregations can resolve tables from Eloquent models
- ✅ Existing Table classes continue to work
- ✅ All existing tests pass
- ✅ New tests for Eloquent resolution

**Testing:**
```php
// Test direct aggregation with Eloquent auto-resolution
test('Sum can resolve table from Eloquent model', function () {
    // Setup: Order model exists with table 'orders'

    $sum = Sum::make('orders.total')->currency('USD');
    $table = $sum->table();

    expect($table)->toBeInstanceOf(EloquentTable::class)
        ->and($table->table())->toBe('orders')
        ->and($table->modelClass())->toBe(Order::class);
});
```

---

### Phase 3: Relation Auto-Detection (Week 4)

**Goal:** Auto-join queries using Eloquent relations

**Tasks:**

1. **Enhance EloquentTable relation introspection** (3 days)
   - Add support for HasOne
   - Add support for polymorphic relations (MorphTo, MorphMany)
   - Handle relation methods with parameters gracefully
   - Comprehensive tests for all relation types

2. **Update JoinResolver to handle EloquentTable** (1 day)
   - Ensure BFS works with both table types
   - Test multi-table queries with mixed sources
   - Performance testing

3. **End-to-end testing** (1 day)
   - Multi-table query using only Eloquent models
   - Multi-table query mixing Eloquent and Manual tables
   - Complex join scenarios

**Deliverables:**
- ✅ Automatic joins work with Eloquent models
- ✅ All relation types supported
- ✅ Mixed Manual/Eloquent queries work

**Testing:**
```php
test('auto-joins work across Eloquent models', function () {
    // No Table classes defined, only Eloquent models

    $results = Slice::query()
        ->metrics([
            Sum::make('orders.total'),
            Sum::make('order_items.price'),
            Count::make('customers.id'),
        ])
        ->dimensions([TimeDimension::make('orders.created_at')->daily()])
        ->get();

    // Should auto-detect:
    // - Order model with orders table
    // - OrderItem model with order_items table
    // - Customer model with customers table
    // - Join: orders -> order_items (hasMany)
    // - Join: orders -> customers (belongsTo)

    expect($results)->not->toBeEmpty();
});
```

---

### Phase 4: Dimension Auto-Mapping (Week 5)

**Goal:** Auto-detect dimensions from Eloquent casts

**Tasks:**

1. **Enhance dimension introspection** (2 days)
   - Map datetime/date casts to TimeDimension
   - Add configuration for custom cast → dimension mappings
   - Support for attribute casting

2. **Create DimensionMapper service** (2 days)
   - Configurable mapping rules
   - Column name heuristics (e.g., 'country_code' → CountryDimension)
   - Override mechanism for special cases

3. **Update documentation** (1 day)
   - Document auto-mapping behavior
   - Show configuration examples
   - Migration guide for existing dimensions

**Deliverables:**
- ✅ TimeDimensions auto-detected from casts
- ✅ Configurable dimension mapping
- ✅ Clear documentation

**Configuration Example:**
```php
// config/slice.php
return [
    'dimension_mappings' => [
        // Cast type → Dimension class
        'datetime' => TimeDimension::class,
        'date' => TimeDimension::class,

        // Column name patterns → Dimension class
        '*_country' => CountryDimension::class,
        '*_country_code' => CountryDimension::class,
        'status' => null, // Auto-create basic Dimension
    ],
];
```

---

### Phase 5: Base Table Resolution (Week 6)

**Goal:** Smart GROUP BY base table detection

**Design Decision:** **Primary table heuristic with explicit override**

**Heuristic Rules:**

1. **Single table:** Use that table as base
2. **Multiple tables with dimensions:** Use table from first dimension
3. **Multiple tables, no dimensions:** Use leftmost metric's table
4. **Ambiguous:** Throw exception with helpful message suggesting `->baseTable()`

**Tasks:**

1. **Implement base table detector** (2 days)
   - Create BaseTableResolver service
   - Implement heuristic rules
   - Add validation for ambiguous cases

2. **Add `baseTable()` method to query builder** (1 day)
   - Allow explicit base table specification
   - Validate specified table exists in query
   - Update query plan generation

3. **Update GROUP BY logic** (1 day)
   - Use base table for primary grouping
   - Handle aggregation of hasMany child metrics
   - Add subquery support where needed

4. **Comprehensive testing** (1 day)
   - Test all heuristic scenarios
   - Test explicit baseTable() specification
   - Test edge cases (circular relations, etc.)

**Deliverables:**
- ✅ Automatic base table detection for common cases
- ✅ Explicit override for complex cases
- ✅ Clear error messages for ambiguous queries

**API Examples:**
```php
// Automatic: Single table
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->get(); // Base: orders

// Automatic: Dimension determines base
Slice::query()
    ->metrics([Sum::make('orders.total'), Sum::make('order_items.price')])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get(); // Base: orders (from dimension)

// Explicit: Override automatic detection
Slice::query()
    ->baseTable('orders')
    ->metrics([Sum::make('orders.total'), Sum::make('order_items.quantity')])
    ->get(); // Base: orders (explicit)

// Error: Ambiguous
Slice::query()
    ->metrics([Sum::make('orders.total'), Sum::make('customers.lifetime_value')])
    ->get(); // Throws: "Ambiguous base table. Please specify ->baseTable('orders' or 'customers')"
```

---

### Phase 6: Caching & Optimization (Week 7)

**Goal:** Production-ready performance

**Tasks:**

1. **Implement model registry caching** (2 days)
   - Artisan command: `php artisan slice:cache`
   - Cache EloquentTable metadata to file
   - Load cache in production mode
   - Invalidation strategy

2. **Add EloquentTable instance caching** (1 day)
   - Cache introspected relations and dimensions
   - Clear cache on model changes (development)
   - Memory-efficient caching

3. **Performance benchmarking** (1 day)
   - Benchmark introspection overhead
   - Benchmark cached vs uncached
   - Optimize hot paths

4. **Optimization** (1 day)
   - Lazy-load relations and dimensions
   - Minimize Reflection usage
   - Profile and optimize

**Deliverables:**
- ✅ < 50ms overhead in production (cached)
- ✅ < 500ms scan time for 100 models
- ✅ Clear cache management commands

**Cache Strategy:**
```php
// bootstrap/cache/slice_models.php (generated by artisan slice:cache)
<?php
return [
    'tableToModel' => [
        'orders' => 'App\\Models\\Order',
        'customers' => 'App\\Models\\Customer',
        'order_items' => 'App\\Models\\OrderItem',
    ],
    'generated_at' => '2025-11-08T12:00:00Z',
];

// EloquentTable lazy-loading
class EloquentTable {
    protected ?array $cachedRelations = null;

    public function relations(): array {
        if ($this->cachedRelations === null) {
            $this->cachedRelations = $this->introspectRelations();
        }
        return $this->cachedRelations;
    }
}
```

**Artisan Commands:**
```bash
# Cache model registry (production)
php artisan slice:cache

# Clear cache
php artisan slice:clear

# Auto-cache in optimize command
php artisan optimize # Calls slice:cache automatically
```

---

### Phase 7: Documentation & Migration (Week 8)

**Goal:** Complete documentation and migration path

**Tasks:**

1. **Update README** (1 day)
   - Eloquent-first examples first
   - Table classes as optional advanced feature
   - Clear comparison of both approaches

2. **Write migration guide** (2 days)
   - Step-by-step migration from Table classes to Eloquent
   - Compatibility matrix
   - Common pitfalls and solutions

3. **Create video tutorials** (1 day)
   - Quick start with Eloquent models
   - Migration from Table classes
   - Advanced: Custom dimensions and relations

4. **Update API documentation** (1 day)
   - Document all new classes
   - Update existing class docs
   - Add examples throughout

**Deliverables:**
- ✅ Comprehensive README with Eloquent-first approach
- ✅ Step-by-step migration guide
- ✅ Video tutorials
- ✅ Complete API documentation

---

## API Design

### Before & After Comparison

#### Simple Query

**Before:**
```php
// Must define Table class
class OrdersTable extends Table {
    protected string $table = 'orders';
    // ... 20+ lines of dimensions/relations
}

// Must define Enum
enum OrdersMetric: string implements MetricContract {
    case Revenue = 'revenue';

    public function table(): Table {
        return new OrdersTable();
    }

    public function get(): MetricContract {
        return Sum::make('orders.total')->currency('USD');
    }
}

// Usage
Slice::query()
    ->metrics([OrdersMetric::Revenue])
    ->get();
```

**After:**
```php
// Just use Eloquent model (already exists)
class Order extends Model {
    protected $casts = ['created_at' => 'datetime', 'total' => 'decimal:2'];

    public function customer() {
        return $this->belongsTo(Customer::class);
    }
}

// Direct usage
Slice::query()
    ->metrics([Sum::make('orders.total')->currency('USD')])
    ->get();
```

**Lines of code:** 30+ → 3 (90% reduction)

#### Multi-Table Query

**Before:**
```php
// Define 3 Table classes: OrdersTable, OrderItemsTable, CustomersTable
// Define 3 Metric enums: OrdersMetric, OrderItemsMetric, CustomersMetric
// Total: ~100 lines of boilerplate

Slice::query()
    ->metrics([
        OrdersMetric::Revenue,
        OrderItemsMetric::TotalQuantity,
        CustomersMetric::Count,
    ])
    ->dimensions([TimeDimension::make('created_at')->daily()])
    ->get();
```

**After:**
```php
// Eloquent models already exist
// Zero additional code needed

Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD'),
        Sum::make('order_items.quantity'),
        Count::make('customers.id'),
    ])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get();
```

**Lines of code:** 100+ → 7 (93% reduction)

### New API Methods

#### 1. Explicit Base Table

```php
Slice::query()
    ->baseTable('orders') // ← NEW
    ->metrics([
        Sum::make('orders.total'),
        Sum::make('order_items.quantity'),
    ])
    ->get();
```

#### 2. Dimension Mapping Configuration

```php
// config/slice.php
return [
    'dimensions' => [
        // Global dimension mappings
        'order' => [
            'created_at' => TimeDimension::make('created_at')->asTimestamp(),
            'status' => Dimension::make('status'),
        ],
        'customer' => [
            'country_code' => CountryDimension::make('country_code'),
        ],
    ],
];

// Override at query time
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->dimensions([
        TimeDimension::make('orders.created_at')->daily(),
        CountryDimension::make('customers.country_code')->only(['US', 'CA']),
    ])
    ->get();
```

#### 3. Model Registration (Manual)

```php
// For models in non-standard locations
use NickPotts\Slice\Support\ModelRegistry;

class AppServiceProvider extends ServiceProvider {
    public function boot() {
        $registry = app(ModelRegistry::class);
        $registry->registerModel(\App\Analytics\Models\Order::class);
        $registry->registerModel(\Legacy\Models\Customer::class);
    }
}
```

### Backward Compatibility API

**All existing code continues to work unchanged:**

```php
// Existing Table classes work exactly as before
class OrdersTable extends Table {
    protected string $table = 'orders';

    public function dimensions(): array {
        return [TimeDimension::class => TimeDimension::make('created_at')];
    }

    public function relations(): array {
        return ['customer' => $this->belongsTo(CustomersTable::class, 'customer_id')];
    }
}

// Existing enums work exactly as before
enum OrdersMetric: string implements MetricContract {
    case Revenue = 'revenue';

    public function table(): Table {
        return new OrdersTable();
    }

    public function get(): MetricContract {
        return Sum::make('orders.total')->currency('USD');
    }
}

// Existing queries work exactly as before
Slice::query()
    ->metrics([OrdersMetric::Revenue])
    ->get();
```

**Resolution Priority:** Manual Table classes take precedence over Eloquent models for the same table name.

---

## Edge Case Handling

### 1. Models in Subdirectories

**Challenge:** `App\Models\Analytics\Order`

**Solution:**
```php
class ModelRegistry {
    protected function scanDirectory(string $directory, string $namespace = 'App\\Models'): void {
        // Recursively scan subdirectories
        $subdirs = glob($directory . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $subdirName = basename($subdir);
            $this->scanDirectory($subdir, $namespace . '\\' . $subdirName);
        }
    }
}
```

**Test:**
```php
test('scans models in subdirectories', function () {
    // Given: App\Models\Analytics\Order exists
    $registry = new ModelRegistry([app_path('Models')]);
    $registry->scan();

    expect($registry->hasModel('orders'))->toBeTrue();
});
```

---

### 2. Custom Table Names

**Challenge:** Model uses custom `$table` property

```php
class Order extends Model {
    protected $table = 'shop_orders'; // ← Custom name
}
```

**Solution:**
```php
class EloquentTable {
    public function __construct(string $modelClass) {
        $model = new $modelClass;
        $this->tableName = $model->getTable(); // ← Respects $table property
    }
}
```

**Test:**
```php
test('respects custom table names', function () {
    $table = EloquentTable::fromModel(Order::class);
    expect($table->table())->toBe('shop_orders');
});
```

---

### 3. Multiple Databases/Connections

**Challenge:** Different models on different connections

```php
class Order extends Model {
    protected $connection = 'mysql';
}

class AnalyticsEvent extends Model {
    protected $connection = 'clickhouse';
}
```

**Solution:**
```php
// TableContract adds connection method
interface TableContract {
    public function connection(): ?string;
}

class EloquentTable implements TableContract {
    protected ?string $connection = null;

    public function __construct(string $modelClass) {
        $model = new $modelClass;
        $this->connection = $model->getConnectionName();
    }

    public function connection(): ?string {
        return $this->connection;
    }
}

// QueryBuilder respects connection
protected function buildDatabasePlan(array $tables, ...): DatabaseQueryPlan {
    $connection = $tables[0]->connection();
    $query = DB::connection($connection)->table($tables[0]->table());
    // ...
}
```

**Validation:** Prevent cross-connection joins
```php
if (count(array_unique(array_map(fn($t) => $t->connection(), $tables))) > 1) {
    throw new InvalidArgumentException(
        'Cannot join tables across different database connections. '.
        'Use separate queries or software joins.'
    );
}
```

---

### 4. Composite Primary Keys

**Challenge:** Model has composite PK

```php
class Enrollment extends Model {
    protected $primaryKey = ['student_id', 'course_id'];
    public $incrementing = false;
}
```

**Solution:** Document limitation, provide workaround

**Limitation:**
- Auto-join detection may not work correctly
- Require manual Table class for complex PK scenarios

**Workaround:**
```php
class EnrollmentTable extends Table {
    protected string $table = 'enrollments';

    public function relations(): array {
        return [
            'student' => new BelongsTo(
                StudentTable::class,
                'student_id',
                'id'
            ),
            'course' => new BelongsTo(
                CourseTable::class,
                'course_id',
                'id'
            ),
        ];
    }
}
```

---

### 5. Polymorphic Relations

**Challenge:** MorphTo, MorphMany relations

```php
class Comment extends Model {
    public function commentable() {
        return $this->morphTo();
    }
}

class Post extends Model {
    public function comments() {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
```

**Solution:** Phase 1 - Skip, Phase 2 - Support

**Phase 1 (MVP):**
- EloquentTable skips polymorphic relations during introspection
- Manual Table class required for polymorphic scenarios

**Phase 2 (Future):**
- Add PolymorphicRelation class to Slice
- Introspect and convert morphTo/morphMany
- Handle join logic for polymorphic queries

**Code:**
```php
protected function convertEloquentRelation($name, $method): ?Relation {
    $eloquentRelation = $method->invoke(new $this->modelClass);

    // Skip polymorphic relations in MVP
    if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo ||
        $eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany) {
        return null; // ← Skip for now
    }

    // Handle BelongsTo, HasMany, etc.
}
```

---

### 6. Soft Deletes

**Challenge:** Should soft-deleted records be auto-filtered?

**Decision:** Respect model's soft delete behavior

**Implementation:**
```php
class EloquentTable {
    protected bool $usesSoftDeletes = false;

    public function __construct(string $modelClass) {
        $model = new $modelClass;
        $this->usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass));
    }

    public function applySoftDeleteFilter(QueryAdapter $query): void {
        if ($this->usesSoftDeletes) {
            $query->whereNull($this->tableName . '.deleted_at');
        }
    }
}

// QueryBuilder applies filter automatically
protected function buildDatabasePlan(array $tables, ...): DatabaseQueryPlan {
    $query = $this->driver->createQuery($tables[0]->table());

    foreach ($tables as $table) {
        if ($table instanceof EloquentTable) {
            $table->applySoftDeleteFilter($query);
        }
    }
    // ...
}
```

**Override:** Provide `withTrashed()` method
```php
Slice::query()
    ->withTrashed() // ← Include soft-deleted records
    ->metrics([Sum::make('orders.total')])
    ->get();
```

---

### 7. Global Scopes

**Challenge:** Model has global scopes

```php
class Order extends Model {
    protected static function booted() {
        static::addGlobalScope('active', function ($query) {
            $query->where('status', '!=', 'cancelled');
        });
    }
}
```

**Decision:** Do NOT apply global scopes automatically

**Rationale:**
- Analytics queries need all data by default
- Scopes can be applied as dimension filters
- Unexpected behavior if scopes auto-apply

**Documentation:**
```php
// Instead of relying on global scopes, use dimension filters
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->dimensions([
        Dimension::make('status')->except(['cancelled']) // ← Explicit filter
    ])
    ->get();
```

---

### 8. Accessors/Mutators

**Challenge:** Model has accessors

```php
class Order extends Model {
    protected $appends = ['formatted_total'];

    public function getFormattedTotalAttribute() {
        return '$' . number_format($this->total, 2);
    }
}
```

**Decision:** Only use actual database columns

**Implementation:**
```php
class EloquentTable {
    protected function introspectDimensions(): array {
        $dimensions = [];
        $model = new $this->modelClass;

        // Only use actual casts, not appended attributes
        $casts = $model->getCasts();

        foreach ($casts as $column => $castType) {
            // Only columns that exist in database
            if ($this->isActualDatabaseColumn($column)) {
                // ... create dimension
            }
        }

        return $dimensions;
    }

    protected function isActualDatabaseColumn(string $column): bool {
        // Check schema or model fillable/guarded
        $model = new $this->modelClass;

        return !in_array($column, $model->getAppends());
    }
}
```

---

### 9. Relation Methods with Parameters

**Challenge:** Relation methods require parameters

```php
class Order extends Model {
    public function itemsByStatus(string $status) {
        return $this->hasMany(OrderItem::class)->where('status', $status);
    }
}
```

**Solution:** Skip during introspection

```php
protected function introspectRelations(): array {
    foreach ($reflector->getMethods() as $method) {
        // Skip methods with required parameters
        if ($method->getNumberOfRequiredParameters() > 0) {
            continue; // ← Skip
        }

        // Try to invoke
        try {
            $eloquentRelation = $method->invoke(new $this->modelClass);
        } catch (Throwable $e) {
            continue; // ← Skip if invoke fails
        }
    }
}
```

---

### 10. Same Table Name, Different Connections

**Challenge:** Two models with same table name on different databases

```php
class Order extends Model {
    protected $connection = 'mysql';
    protected $table = 'orders';
}

class ArchivedOrder extends Model {
    protected $connection = 'archive_db';
    protected $table = 'orders'; // ← Same name!
}
```

**Solution:** Registry key includes connection

```php
class ModelRegistry {
    protected array $tables = []; // connection.table_name => EloquentTable

    public function registerModel(string $modelClass): void {
        $model = new $modelClass;
        $connection = $model->getConnectionName();
        $tableName = $model->getTable();

        $key = $connection . '.' . $tableName;
        $this->tables[$key] = EloquentTable::fromModel($modelClass);
    }

    public function lookup(string $tableName, ?string $connection = null): ?TableContract {
        $connection = $connection ?? config('database.default');
        $key = $connection . '.' . $tableName;

        return $this->tables[$key] ?? null;
    }
}
```

**Usage:**
```php
Sum::make('mysql.orders.total') // ← Prefix with connection
Sum::make('archive_db.orders.total')
```

---

## Testing Strategy

### Unit Tests

#### EloquentTable Tests
```php
describe('EloquentTable', function () {
    it('introspects table name from model', function () {
        $table = EloquentTable::fromModel(Order::class);
        expect($table->table())->toBe('orders');
    });

    it('introspects BelongsTo relations', function () {
        $table = EloquentTable::fromModel(Order::class);
        $relations = $table->relations();

        expect($relations)->toHaveKey('customer')
            ->and($relations['customer'])->toBeInstanceOf(BelongsTo::class)
            ->and($relations['customer']->foreignKey())->toBe('customer_id');
    });

    it('introspects HasMany relations', function () {
        $table = EloquentTable::fromModel(Order::class);
        $relations = $table->relations();

        expect($relations)->toHaveKey('items')
            ->and($relations['items'])->toBeInstanceOf(HasMany::class);
    });

    it('introspects datetime casts as TimeDimension', function () {
        $table = EloquentTable::fromModel(Order::class);
        $dimensions = $table->dimensions();

        expect($dimensions)->toHaveKey(TimeDimension::class.'::created_at')
            ->and($dimensions[TimeDimension::class.'::created_at'])->toBeInstanceOf(TimeDimension::class);
    });

    it('caches introspection results', function () {
        $table = EloquentTable::fromModel(Order::class);

        $relations1 = $table->relations();
        $relations2 = $table->relations();

        expect($relations1)->toBe($relations2); // Same instance
    });
});
```

#### ModelRegistry Tests
```php
describe('ModelRegistry', function () {
    it('scans directory for models', function () {
        $registry = new ModelRegistry([__DIR__ . '/fixtures/Models']);
        $registry->scan();

        expect($registry->hasModel('orders'))->toBeTrue()
            ->and($registry->hasModel('customers'))->toBeTrue();
    });

    it('handles subdirectories', function () {
        $registry = new ModelRegistry([__DIR__ . '/fixtures/Models']);
        $registry->scan();

        expect($registry->hasModel('analytics_events'))->toBeTrue();
    });

    it('caches registry to file', function () {
        $registry = new ModelRegistry([__DIR__ . '/fixtures/Models']);
        $registry->scan();

        $cachePath = __DIR__ . '/fixtures/cache.php';
        $registry->cache($cachePath);

        expect(file_exists($cachePath))->toBeTrue();

        $newRegistry = new ModelRegistry();
        $newRegistry->loadCache($cachePath);

        expect($newRegistry->hasModel('orders'))->toBeTrue();

        unlink($cachePath);
    });
});
```

#### TableResolver Tests
```php
describe('TableResolver', function () {
    it('parses table.column notation', function () {
        $resolver = new TableResolver(
            new ModelRegistry(),
            new Registry()
        );

        $result = $resolver->parseTableColumn('orders.total');

        expect($result)->toBe(['table' => 'orders', 'column' => 'total']);
    });

    it('throws on invalid notation', function () {
        $resolver = new TableResolver(
            new ModelRegistry(),
            new Registry()
        );

        expect(fn() => $resolver->parseTableColumn('invalid'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('resolves manual table first (backward compat)', function () {
        $manualRegistry = new Registry();
        $manualRegistry->registerTable(new OrdersTable());

        $modelRegistry = new ModelRegistry();
        $modelRegistry->registerModel(Order::class);

        $resolver = new TableResolver($modelRegistry, $manualRegistry);
        $table = $resolver->resolveTable('orders');

        expect($table)->toBeInstanceOf(Table::class); // Manual, not EloquentTable
    });

    it('resolves Eloquent table if no manual table exists', function () {
        $modelRegistry = new ModelRegistry();
        $modelRegistry->registerModel(Order::class);

        $resolver = new TableResolver($modelRegistry, new Registry());
        $table = $resolver->resolveTable('orders');

        expect($table)->toBeInstanceOf(EloquentTable::class);
    });
});
```

### Integration Tests

#### End-to-End Eloquent Query
```php
test('full query using only Eloquent models', function () {
    // Setup: Create Order, Customer, OrderItem models with relations
    // No Table classes defined

    $results = Slice::query()
        ->metrics([
            Sum::make('orders.total')->label('Revenue'),
            Count::make('orders.id')->label('Order Count'),
            Sum::make('order_items.quantity')->label('Items Sold'),
        ])
        ->dimensions([
            TimeDimension::make('orders.created_at')->daily(),
        ])
        ->get();

    expect($results)->not->toBeEmpty()
        ->and($results->first())->toHaveKeys(['orders_total', 'orders_id', 'order_items_quantity', 'orders_created_at_day']);
});
```

#### Mixed Manual and Eloquent Tables
```php
test('query with both manual and Eloquent tables', function () {
    // OrdersTable manually defined
    // Customer model auto-detected

    $results = Slice::query()
        ->metrics([
            OrdersMetric::Revenue, // Uses manual OrdersTable
            Count::make('customers.id'), // Uses EloquentTable
        ])
        ->get();

    expect($results)->not->toBeEmpty();
});
```

### Feature Tests

#### Base Table Detection
```php
test('auto-detects base table from dimensions', function () {
    $results = Slice::query()
        ->metrics([
            Sum::make('orders.total'),
            Sum::make('order_items.quantity'),
        ])
        ->dimensions([TimeDimension::make('orders.created_at')->daily()])
        ->get();

    // Should use 'orders' as base table (from dimension)
    expect($results)->not->toBeEmpty();
});

test('uses explicit base table', function () {
    $results = Slice::query()
        ->baseTable('orders')
        ->metrics([
            Sum::make('orders.total'),
            Sum::make('order_items.quantity'),
        ])
        ->get();

    expect($results)->not->toBeEmpty();
});

test('throws on ambiguous base table', function () {
    expect(fn() => Slice::query()
        ->metrics([
            Sum::make('orders.total'),
            Sum::make('customers.lifetime_value'),
        ])
        ->get()
    )->toThrow(InvalidArgumentException::class, 'Ambiguous base table');
});
```

### Performance Tests

```php
test('introspection completes within 500ms for 100 models', function () {
    $start = microtime(true);

    $registry = new ModelRegistry([app_path('Models')]);
    $registry->scan(); // Scan 100+ models

    $duration = (microtime(true) - $start) * 1000;

    expect($duration)->toBeLessThan(500);
});

test('cached lookup completes within 1ms', function () {
    $registry = new ModelRegistry();
    $registry->loadCache(storage_path('framework/cache/slice_models.php'));

    $start = microtime(true);
    $table = $registry->lookup('orders');
    $duration = (microtime(true) - $start) * 1000;

    expect($table)->not->toBeNull()
        ->and($duration)->toBeLessThan(1);
});
```

### Database-Specific Tests

```php
test('works with MySQL', function () {
    config(['database.default' => 'mysql']);

    $results = Slice::query()
        ->metrics([Sum::make('orders.total')])
        ->dimensions([TimeDimension::make('orders.created_at')->daily()])
        ->get();

    expect($results)->not->toBeEmpty();
});

test('works with PostgreSQL', function () {
    config(['database.default' => 'pgsql']);

    $results = Slice::query()
        ->metrics([Sum::make('orders.total')])
        ->dimensions([TimeDimension::make('orders.created_at')->daily()])
        ->get();

    expect($results)->not->toBeEmpty();
});

// Repeat for SQLite, SQL Server, etc.
```

### Backward Compatibility Tests

```php
test('existing Table classes still work', function () {
    // Use existing OrdersTable and OrdersMetric enum
    $results = Slice::query()
        ->metrics([OrdersMetric::Revenue])
        ->dimensions([TimeDimension::make('created_at')->daily()])
        ->get();

    expect($results)->not->toBeEmpty();
});

test('string-based queries still work', function () {
    // Existing Registry with metric enums
    $results = Slice::query()
        ->metrics(['orders.revenue'])
        ->get();

    expect($results)->not->toBeEmpty();
});
```

---

## Performance Analysis

### Introspection Overhead

**Scenario:** First query using Eloquent models (uncached)

| Operation | Time | Optimization |
|-----------|------|--------------|
| Scan 100 models | 300-500ms | Cache in production |
| Introspect 1 model relations | 5-10ms | Lazy load |
| Introspect 1 model dimensions | 2-5ms | Lazy load |
| Build EloquentTable instance | 1-2ms | Singleton per table |
| **Total (uncached)** | **400-600ms** | **Acceptable for dev** |

**Scenario:** Subsequent queries (cached)

| Operation | Time | Method |
|-----------|------|--------|
| Load cached registry | 5-10ms | File cache |
| Lookup table | <1ms | Array access |
| Get cached relations | <1ms | Property access |
| **Total (cached)** | **< 20ms** | **Production-ready** |

### Caching Strategy

**Development (auto-scan):**
```php
// SliceServiceProvider
public function boot() {
    if (app()->environment('local')) {
        app(ModelRegistry::class)->scan(); // Scan on every request
    } else {
        app(ModelRegistry::class)->loadCache(
            storage_path('framework/cache/slice_models.php')
        );
    }
}
```

**Production (cached):**
```bash
# Deploy script
php artisan slice:cache
php artisan config:cache
php artisan route:cache
```

**Cache Invalidation:**
```php
// Clear cache when models change
php artisan slice:clear

// Or automatically via file watcher (development)
// Watch app/Models/** → clear cache on change
```

### Memory Usage

**Uncached (100 models):**
- ModelRegistry: ~500KB (table → model mappings)
- 10 EloquentTable instances: ~200KB (relations + dimensions cached)
- **Total:** ~700KB

**Cached:**
- Cached file: ~100KB (serialized mappings)
- Runtime memory: ~300KB (loaded cache + instances)
- **Total:** ~300KB

**Optimization:** Lazy-load relations and dimensions (only introspect when accessed)

---

## Migration Guide

### Step-by-Step Migration

#### Step 1: Update Composer (Zero Breaking Changes)

```bash
composer update nick-potts/slice
```

All existing code works unchanged. New features are opt-in.

#### Step 2: Try Direct Aggregations (No Migration Required)

Add a new metric using direct aggregation:

```php
// Before: Would need to create Table + Enum
// After: Just write the query
Slice::query()
    ->metrics([
        Sum::make('orders.shipping_cost')->currency('USD'), // ← NEW metric, no Table needed
        OrdersMetric::Revenue, // ← Existing enum still works
    ])
    ->get();
```

**Result:** Both old and new styles work together.

#### Step 3: Gradually Remove Table Classes (Optional)

For each Table class, check if you can remove it:

**Can remove if:**
- ✅ Table name matches Eloquent model
- ✅ Relations defined in Eloquent model
- ✅ Dimensions auto-detectable from casts

**Must keep if:**
- ❌ Custom cross-joins (no FK relationship)
- ❌ Complex dimension mappings
- ❌ Non-Eloquent data source (API, Clickhouse)

**Example Migration:**

```php
// Before: OrdersTable.php (can be deleted)
class OrdersTable extends Table {
    protected string $table = 'orders';

    public function dimensions(): array {
        return [
            TimeDimension::class => TimeDimension::make('created_at'),
        ];
    }

    public function relations(): array {
        return [
            'customer' => $this->belongsTo(CustomersTable::class, 'customer_id'),
            'items' => $this->hasMany(OrderItemsTable::class, 'order_id'),
        ];
    }
}

// After: Order.php (already exists)
class Order extends Model {
    protected $casts = [
        'created_at' => 'datetime', // ← Auto-detected as TimeDimension
        'total' => 'decimal:2',
    ];

    public function customer() {
        return $this->belongsTo(Customer::class); // ← Auto-detected as BelongsTo relation
    }

    public function items() {
        return $this->hasMany(OrderItem::class); // ← Auto-detected as HasMany relation
    }
}

// Delete OrdersTable.php ← Safe to remove
```

#### Step 4: Migrate Enums (Optional)

Enums provide type safety but aren't required. You can:

**Option A: Keep enums** (recommended for stable APIs)
```php
enum OrdersMetric: string implements MetricContract {
    case Revenue = 'revenue';

    public function table(): TableContract {
        // Change from manual Table to Eloquent
        return EloquentTable::fromModel(Order::class);
    }

    public function get(): MetricContract {
        return Sum::make('orders.total')->currency('USD');
    }
}
```

**Option B: Remove enums** (use direct aggregations)
```php
// Instead of: OrdersMetric::Revenue
// Use: Sum::make('orders.total')->currency('USD')
```

**Option C: Hybrid** (common metrics in enum, ad-hoc metrics direct)
```php
Slice::query()
    ->metrics([
        OrdersMetric::Revenue, // ← Enum (type-safe, IDE autocomplete)
        Sum::make('orders.shipping_cost')->currency('USD'), // ← Direct (ad-hoc)
    ])
    ->get();
```

#### Step 5: Cache in Production

```bash
# Add to deployment script
php artisan slice:cache
```

Creates `storage/framework/cache/slice_models.php` with model mappings.

---

### Compatibility Matrix

| Feature | Manual Table | Eloquent Table | Mixed |
|---------|--------------|----------------|-------|
| **Basic Queries** | ✅ | ✅ | ✅ |
| **Multi-table Joins** | ✅ | ✅ | ✅ |
| **Dimensions** | ✅ | ✅ (auto-detected) | ✅ |
| **Relations** | ✅ | ✅ (auto-detected) | ✅ |
| **Custom CrossJoins** | ✅ | ❌ (use Manual Table) | ✅ |
| **Non-Eloquent Sources** | ✅ | ❌ | ✅ |
| **Type Safety (Enums)** | ✅ | ✅ | ✅ |
| **String Queries** | ✅ | ❌ (not in Registry) | ✅ |

**Key Insight:** Manual Table classes are now **advanced/edge-case features**, not required for standard Eloquent usage.

---

### Common Pitfalls & Solutions

#### Pitfall 1: Table Name Mismatch

**Problem:**
```php
class Order extends Model {
    // Table name is 'shop_orders', not 'orders'
    protected $table = 'shop_orders';
}

Sum::make('orders.total') // ← Fails! No model for 'orders'
```

**Solution:**
```php
Sum::make('shop_orders.total') // ← Use actual table name
```

#### Pitfall 2: Relation Not Auto-Detected

**Problem:**
```php
class Order extends Model {
    public function customer() {
        // Missing return type hint
        return $this->belongsTo(Customer::class);
    }
}
```

**Solution:**
```php
class Order extends Model {
    public function customer(): BelongsTo { // ← Add return type
        return $this->belongsTo(Customer::class);
    }
}
```

#### Pitfall 3: Dimension Not Auto-Detected

**Problem:**
```php
class Order extends Model {
    // No cast for created_at
}

TimeDimension::make('orders.created_at')->daily() // ← Not in dimensions()
```

**Solution:**
```php
class Order extends Model {
    protected $casts = [
        'created_at' => 'datetime', // ← Add cast
    ];
}
```

Or explicitly pass dimension to query (no auto-detection needed):
```php
Slice::query()
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get(); // ← Works even without cast
```

---

## Risk Assessment

### High Risk Areas

#### 1. Backward Compatibility

**Risk:** Breaking existing Table classes

**Mitigation:**
- ✅ TableContract interface keeps existing API
- ✅ Manual Tables have priority over Eloquent
- ✅ Comprehensive backward compat test suite
- ✅ Beta release for community testing

**Probability:** Low
**Impact:** Critical
**Overall:** Medium

---

#### 2. Performance Regression

**Risk:** Introspection overhead slows queries

**Mitigation:**
- ✅ Caching in production (<20ms overhead)
- ✅ Lazy-loading of relations/dimensions
- ✅ Benchmarking suite
- ✅ Performance budgets in CI

**Probability:** Low
**Impact:** High
**Overall:** Medium

---

#### 3. Complex Relation Scenarios

**Risk:** Edge cases not handled (polymorphic, composite keys, etc.)

**Mitigation:**
- ✅ Document limitations clearly
- ✅ Fallback to Manual Table classes
- ✅ Incremental support (MVP first, edge cases later)
- ✅ Community feedback loop

**Probability:** Medium
**Impact:** Medium
**Overall:** Medium

---

### Medium Risk Areas

#### 4. Cache Invalidation

**Risk:** Stale cache after model changes

**Mitigation:**
- ✅ Auto-scan in development
- ✅ Clear cache command
- ✅ Hook into `php artisan optimize`
- ✅ Documentation on deployment

**Probability:** Medium
**Impact:** Low
**Overall:** Low

---

#### 5. Multi-Database Edge Cases

**Risk:** Connection handling bugs

**Mitigation:**
- ✅ Validation prevents cross-connection joins
- ✅ Tests for all 7 database drivers
- ✅ Clear error messages

**Probability:** Low
**Impact:** Medium
**Overall:** Low

---

### Low Risk Areas

#### 6. Model Scanning Performance

**Risk:** Slow scan with 1000+ models

**Mitigation:**
- ✅ Only in development (cached in production)
- ✅ Configurable scan paths
- ✅ Parallel scanning (future optimization)

**Probability:** Low
**Impact:** Low
**Overall:** Very Low

---

## Conclusion

This Eloquent-first architecture refactor will:

1. ✅ **Reduce code by 90%+** for common use cases
2. ✅ **Maintain 100% backward compatibility** with existing Table classes
3. ✅ **Auto-detect relations and dimensions** from Eloquent models
4. ✅ **Support all 7 database drivers** without changes
5. ✅ **Cache for production** with <20ms overhead
6. ✅ **Phase implementation** over 8 weeks with continuous testing

**Next Steps:**
1. Review this plan with stakeholders
2. Create feature branch: `feature/eloquent-first`
3. Begin Phase 1: Foundation (Week 1-2)
4. Iterate based on feedback

**Success Metrics:**
- Zero breaking changes in existing tests
- 90%+ reduction in boilerplate for new projects
- <50ms production overhead (cached)
- Documentation coverage >80%

---

**Document Status:** Ready for Review
**Estimated Effort:** 8 weeks (1 developer)
**Complexity:** High
**Value:** Very High
