# Schema Provider Architecture Refactor - Comprehensive Design

**Document Version:** 2.0
**Date:** 2025-11-08
**Status:** Design Phase - Provider-Agnostic Approach

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Provider-Agnostic Philosophy](#provider-agnostic-philosophy)
3. [Architecture Design](#architecture-design)
4. [Schema Provider Plugin Model](#schema-provider-plugin-model)
5. [Implementation Phases](#implementation-phases)
6. [API Design](#api-design)
7. [Edge Case Handling](#edge-case-handling)
8. [Testing Strategy](#testing-strategy)
9. [Performance Analysis](#performance-analysis)
10. [Migration Guide](#migration-guide)

---

## Executive Summary

### Vision

Transform Slice from requiring **explicit Table class definitions** to a **pluggable schema provider architecture** where:
- Developers can use `Sum::make('orders.total')` without defining Table classes
- **Eloquent models** are automatically introspected via `EloquentSchemaProvider`
- **ClickHouse**, **HTTP APIs**, or **any data source** can implement `SchemaProvider`
- **Manual Table classes** remain as a first-class provider (not legacy)
- Zero breaking changes for existing users

### Key Architectural Shift

**Before:** Table-centric, single-source schema

```
User → Manual Table Class → Registry → Query Engine
```

**After:** Provider-agnostic, pluggable schema sources

```
User → SchemaProviderManager → [Manual, Eloquent, ClickHouse, Custom] → Query Engine
```

### Success Criteria

1. ✅ **Zero breaking changes** - Existing Table classes work via `ManualTableProvider`
2. ✅ **Pluggable architecture** - Any data source can implement `SchemaProvider`
3. ✅ **Eloquent out-of-box** - `EloquentSchemaProvider` ships with package
4. ✅ **Type safety** - Maintain enum-based metrics alongside direct aggregations
5. ✅ **Multi-database** - Support all 7 existing database drivers

---

## Provider-Agnostic Philosophy

### Core Principle

**Schema sources should be pluggable.** Whether metadata comes from:
- **Eloquent models** (reflection-based introspection)
- **Manual Table classes** (hand-authored definitions)
- **ClickHouse** (system table introspection)
- **HTTP APIs** (OpenAPI/GraphQL schema)
- **YAML/JSON** (static configuration files)
- **Custom sources** (your domain-specific needs)

...the query engine should consume them uniformly via `SchemaProvider`.

### Why Provider-Agnostic?

1. **Flexibility:** Not everyone uses Eloquent (data warehouses, microservices, etc.)
2. **Extensibility:** Community can build providers for any data source
3. **Future-proof:** New data sources don't require core changes
4. **Backward compat:** Manual tables become a provider, not legacy code
5. **Separation of concerns:** Schema discovery ≠ query execution

### Provider Examples

| Provider | Use Case | Discovery Method |
|----------|----------|------------------|
| `ManualTableProvider` | Existing Table classes, custom sources | Developer-authored classes |
| `EloquentSchemaProvider` | Laravel Eloquent models | Reflection + model metadata |
| `ClickHouseProvider` | ClickHouse OLAP database | System table introspection |
| `OpenAPIProvider` | REST APIs with OpenAPI spec | Schema parsing |
| `GraphQLProvider` | GraphQL APIs | Introspection query |
| `ConfigProvider` | Static YAML/JSON definitions | Config file parsing |

---

## Architecture Design

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         USER API LAYER                          │
│  Slice::query()->metrics([...])->dimensions([...])->get()      │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                   METRIC SOURCE PARSER                          │
│  Parses: 'orders.total', 'connection:table.col', Model::class  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Asks SchemaProviderManager: "Who provides 'orders'?"    │  │
│  │ Returns: (SliceSource, column, connection)            │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                  SCHEMA PROVIDER MANAGER                        │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Orchestrates multiple providers                         │  │
│  │ Priority order: Explicit → Override → Registered        │  │
│  │ Caching: Delegates to CachableSchemaProvider            │  │
│  └──────────────────────────────────────────────────────────┘  │
│                             ↓                                   │
│  ┌────────────────────────────────────────────────────────┐   │
│  │ Provider Registry                                      │   │
│  │ ┌─────────────┐ ┌──────────────┐ ┌─────────────────┐  │   │
│  │ │   Manual    │ │   Eloquent   │ │   ClickHouse   │  │   │
│  │ │  Provider   │ │   Provider   │ │    Provider    │  │   │
│  │ └─────────────┘ └──────────────┘ └─────────────────┘  │   │
│  │        ↓                ↓                  ↓           │   │
│  │   Table classes    Reflection      System tables      │   │
│  └────────────────────────────────────────────────────────┘   │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                     TABLE CONTRACT LAYER                        │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ SliceSource (interface)                                │  │
│  │ - name(): string                                         │  │
│  │ - connection(): ?string                                  │  │
│  │ - relations(): RelationGraph                             │  │
│  │ - dimensions(): DimensionCatalog                         │  │
│  │ - primaryKey(): PrimaryKeyDescriptor                     │  │
│  └──────────────────────────────────────────────────────────┘  │
│           ↑                    ↑                  ↑             │
│  ┌────────┴────────┐  ┌───────┴──────┐  ┌────────┴──────┐     │
│  │ ManualTable     │  │ MetadataBacked│  │ ClickHouse   │     │
│  │ (wraps legacy)  │  │     Table     │  │    Table     │     │
│  └─────────────────┘  └──────────────┘  └───────────────┘     │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                    QUERY ENGINE (Unchanged)                     │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ QueryBuilder → BaseTableResolver                         │  │
│  │            → RelationPathWalker                          │  │
│  │            → DimensionResolver                           │  │
│  │            → JoinResolver                                │  │
│  │            → QueryDriver/Grammar                         │  │
│  └──────────────────────────────────────────────────────────┘  │
│                             ↓                                   │
│                         SQL Execution                           │
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
1. User writes: Sum::make('orders.total')
   ↓
2. MetricSourceParser parses 'orders.total' → {table: 'orders', column: 'total'}
   ↓
3. SchemaProviderManager resolves 'orders':
   - Checks ManualTableProvider: "Do you provide 'orders'?" → No
   - Checks EloquentSchemaProvider: "Do you provide 'orders'?" → Yes (Order model)
   - Returns: SliceSource instance
   ↓
4. MetricSourceParser returns: (SliceSource, 'total', 'mysql')
   ↓
5. QueryBuilder consumes SliceSource:
   - relations() → Auto-join setup
   - dimensions() → Dimension resolution
   - primaryKey() → Base table detection
   ↓
6. Query execution proceeds as normal
```

---

## Schema Provider Plugin Model

### SchemaProvider Contract

```php
namespace NickPotts\Slice\Contracts;

interface SchemaProvider
{
    /**
     * Boot the provider with caching support.
     */
    public function boot(SchemaCache $cache): void;

    /**
     * Get all tables this provider can supply.
     *
     * @return iterable<SliceSource>
     */
    public function tables(): iterable;

    /**
     * Check if this provider can supply a specific table.
     *
     * @param string $identifier Table name, model class, or custom identifier
     */
    public function provides(string $identifier): bool;

    /**
     * Resolve a metric source reference to table + column metadata.
     *
     * @param string $reference e.g., 'orders.total', 'App\Models\Order::total'
     * @return MetricSource
     */
    public function resolveMetricSource(string $reference): MetricSource;

    /**
     * Get relation graph for a table.
     */
    public function relations(string $table): RelationGraph;

    /**
     * Get dimension catalog for a table.
     */
    public function dimensions(string $table): DimensionCatalog;

    /**
     * Get provider priority (lower = higher priority).
     */
    public function priority(): int;

    /**
     * Get provider name for debugging.
     */
    public function name(): string;
}
```

### Optional: CachableSchemaProvider

```php
namespace NickPotts\Slice\Contracts;

interface CachableSchemaProvider extends SchemaProvider
{
    /**
     * Generate cache key for this provider's metadata.
     */
    public function cacheKey(): string;

    /**
     * Serialize provider metadata to cache.
     *
     * @return array
     */
    public function toCache(): array;

    /**
     * Restore provider from cached metadata.
     *
     * @param array $cached
     */
    public function fromCache(array $cached): void;

    /**
     * Check if cache is still valid.
     *
     * @return bool
     */
    public function isCacheValid(): bool;
}
```

### SchemaProviderManager

```php
namespace NickPotts\Slice\Support;

class SchemaProviderManager
{
    /** @var array<SchemaProvider> */
    protected array $providers = [];

    /** @var array<string, SliceSource> Explicitly registered tables */
    protected array $explicitTables = [];

    /** @var array<string, SchemaProvider> Override provider per table */
    protected array $overrides = [];

    protected SchemaCache $cache;

    /**
     * Register a provider.
     */
    public function register(SchemaProvider $provider): void
    {
        $this->providers[] = $provider;

        // Sort by priority
        usort($this->providers, fn($a, $b) => $a->priority() <=> $b->priority());

        $provider->boot($this->cache);
    }

    /**
     * Register an explicit table (highest priority).
     */
    public function registerTable(SliceSource $table): void
    {
        $this->explicitTables[$table->name()] = $table;
    }

    /**
     * Override provider for specific table.
     */
    public function override(string $table, SchemaProvider $provider): void
    {
        $this->overrides[$table] = $provider;
    }

    /**
     * Resolve a table by name/identifier.
     *
     * Resolution order:
     * 1. Explicitly registered tables
     * 2. Override providers
     * 3. Registered providers (by priority)
     * 4. Throw exception
     */
    public function resolve(string $identifier): SliceSource
    {
        // 1. Explicit tables
        if (isset($this->explicitTables[$identifier])) {
            return $this->explicitTables[$identifier];
        }

        // 2. Override provider
        if (isset($this->overrides[$identifier])) {
            $provider = $this->overrides[$identifier];
            if ($provider->provides($identifier)) {
                return $this->resolveFromProvider($provider, $identifier);
            }
        }

        // 3. Registered providers (by priority)
        foreach ($this->providers as $provider) {
            if ($provider->provides($identifier)) {
                return $this->resolveFromProvider($provider, $identifier);
            }
        }

        // 4. Not found
        throw new TableNotFoundException(
            "Table '{$identifier}' not found. " .
            "Available providers: " . implode(', ', array_map(fn($p) => $p->name(), $this->providers))
        );
    }

    /**
     * Parse metric source notation.
     *
     * Supports:
     * - 'orders.total'
     * - 'connection:orders.total'
     * - 'App\Models\Order::total'
     */
    public function parseMetricSource(string $reference): MetricSource
    {
        // Parse connection prefix
        if (str_contains($reference, ':')) {
            [$connection, $rest] = explode(':', $reference, 2);
        } else {
            $connection = null;
            $rest = $reference;
        }

        // Parse table.column or Model::column
        if (str_contains($rest, '::')) {
            // Model class reference: App\Models\Order::total
            [$modelClass, $column] = explode('::', $rest, 2);
            $table = $this->resolve($modelClass);
        } else {
            // Table name reference: orders.total
            [$tableName, $column] = explode('.', $rest, 2);
            $table = $this->resolve($tableName);
        }

        return new MetricSource(
            table: $table,
            column: $column,
            connection: $connection ?? $table->connection()
        );
    }

    /**
     * Get all tables from all providers.
     */
    public function allTables(): array
    {
        $tables = $this->explicitTables;

        foreach ($this->providers as $provider) {
            foreach ($provider->tables() as $table) {
                if (!isset($tables[$table->name()])) {
                    $tables[$table->name()] = $table;
                }
            }
        }

        return $tables;
    }

    protected function resolveFromProvider(SchemaProvider $provider, string $identifier): SliceSource
    {
        $source = $provider->resolveMetricSource($identifier . '.id'); // Dummy column
        return $source->table();
    }
}
```

### MetricSource Value Object

```php
namespace NickPotts\Slice\Support;

class MetricSource
{
    public function __construct(
        public readonly SliceSource $table,
        public readonly string $column,
        public readonly ?string $connection = null,
    ) {}
}
```

---

## Built-In Providers

### 1. ManualTableProvider

**Purpose:** Backward compatibility with existing Table classes

```php
namespace NickPotts\Slice\Providers;

class ManualTableProvider implements SchemaProvider
{
    /** @var array<string, Table> */
    protected array $tables = [];

    public function register(Table $table): void
    {
        $this->tables[$table->table()] = $table;
    }

    public function boot(SchemaCache $cache): void
    {
        // No-op: Manual tables don't need caching
    }

    public function tables(): iterable
    {
        foreach ($this->tables as $table) {
            yield new ManualTableAdapter($table);
        }
    }

    public function provides(string $identifier): bool
    {
        return isset($this->tables[$identifier]);
    }

    public function resolveMetricSource(string $reference): MetricSource
    {
        [$tableName, $column] = explode('.', $reference, 2);

        if (!isset($this->tables[$tableName])) {
            throw new InvalidArgumentException("Table '{$tableName}' not registered");
        }

        $table = new ManualTableAdapter($this->tables[$tableName]);

        return new MetricSource($table, $column);
    }

    public function relations(string $table): RelationGraph
    {
        return $this->tables[$table]->relations();
    }

    public function dimensions(string $table): DimensionCatalog
    {
        return $this->tables[$table]->dimensions();
    }

    public function priority(): int
    {
        return 100; // Higher priority (lower number) than auto-discovery
    }

    public function name(): string
    {
        return 'manual';
    }
}
```

### 2. EloquentSchemaProvider

**Purpose:** Auto-introspect Laravel Eloquent models

```php
namespace NickPotts\Slice\Providers;

class EloquentSchemaProvider implements CachableSchemaProvider
{
    /** @var array<string> Model namespaces to scan */
    protected array $namespaces = [];

    /** @var array<string, ModelMetadata> Discovered models */
    protected array $models = [];

    /** @var bool */
    protected bool $scanned = false;

    public function __construct(array $namespaces = null)
    {
        $this->namespaces = $namespaces ?? [config('slice.eloquent.namespaces', 'App\\Models')];
    }

    public function boot(SchemaCache $cache): void
    {
        if ($cache->has($this->cacheKey()) && $this->isCacheValid()) {
            $this->fromCache($cache->get($this->cacheKey()));
        } else {
            $this->scan();
            $cache->put($this->cacheKey(), $this->toCache());
        }
    }

    public function tables(): iterable
    {
        $this->ensureScanned();

        foreach ($this->models as $metadata) {
            yield new MetadataBackedTable($metadata);
        }
    }

    public function provides(string $identifier): bool
    {
        $this->ensureScanned();

        // Check by table name
        foreach ($this->models as $metadata) {
            if ($metadata->tableName === $identifier) {
                return true;
            }
        }

        // Check by model class
        return isset($this->models[$identifier]);
    }

    public function resolveMetricSource(string $reference): MetricSource
    {
        $this->ensureScanned();

        // Parse: 'orders.total' or 'App\Models\Order::total'
        if (str_contains($reference, '::')) {
            [$modelClass, $column] = explode('::', $reference, 2);
            $metadata = $this->models[$modelClass] ?? null;
        } else {
            [$tableName, $column] = explode('.', $reference, 2);
            $metadata = $this->findByTableName($tableName);
        }

        if (!$metadata) {
            throw new InvalidArgumentException("Model for '{$reference}' not found");
        }

        return new MetricSource(
            table: new MetadataBackedTable($metadata),
            column: $column,
            connection: $metadata->connection
        );
    }

    public function relations(string $table): RelationGraph
    {
        $metadata = $this->findByTableName($table);
        return $metadata->relationGraph;
    }

    public function dimensions(string $table): DimensionCatalog
    {
        $metadata = $this->findByTableName($table);
        return $metadata->dimensionCatalog;
    }

    public function priority(): int
    {
        return 200; // Lower priority than manual
    }

    public function name(): string
    {
        return 'eloquent';
    }

    /**
     * Scan configured namespaces for Eloquent models.
     */
    protected function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        foreach ($this->namespaces as $namespace) {
            $this->scanNamespace($namespace);
        }

        $this->scanned = true;
    }

    protected function scanNamespace(string $namespace): void
    {
        $path = $this->namespaceToPath($namespace);

        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->pathToClass($file->getPathname(), $namespace);

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isSubclassOf(Model::class) && !$reflection->isAbstract()) {
                $this->introspectModel($class);
            }
        }
    }

    /**
     * Introspect a single Eloquent model.
     */
    protected function introspectModel(string $modelClass): void
    {
        // Use newInstanceWithoutConstructor to avoid boot logic
        $reflection = new \ReflectionClass($modelClass);
        $model = $reflection->newInstanceWithoutConstructor();

        // Call necessary initialization
        $model->syncOriginal();

        $metadata = new ModelMetadata(
            modelClass: $modelClass,
            tableName: $model->getTable(),
            connection: $model->getConnectionName(),
            primaryKey: $this->introspectPrimaryKey($model),
            relationGraph: $this->introspectRelations($modelClass, $reflection),
            dimensionCatalog: $this->introspectDimensions($model),
            softDeletes: $this->hasSoftDeletes($modelClass),
            timestamps: $model->timestamps,
        );

        $this->models[$modelClass] = $metadata;
    }

    /**
     * Introspect relations using Reflection.
     */
    protected function introspectRelations(string $modelClass, \ReflectionClass $reflection): RelationGraph
    {
        $relations = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited methods from Model class
            if ($method->class === Model::class) {
                continue;
            }

            // Skip methods with required parameters
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Check return type
            $returnType = $method->getReturnType();
            if (!$returnType instanceof \ReflectionNamedType) {
                continue;
            }

            $returnTypeName = $returnType->getName();

            // Check if it's an Eloquent relation
            if (!$this->isEloquentRelationType($returnTypeName)) {
                continue;
            }

            // Try to invoke the relation method
            try {
                $model = $reflection->newInstanceWithoutConstructor();
                $relation = $method->invoke($model);

                if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    continue;
                }

                $descriptor = $this->convertEloquentRelation($method->getName(), $relation);

                if ($descriptor) {
                    $relations[$method->getName()] = $descriptor;
                }
            } catch (\Throwable $e) {
                // Skip relations that can't be invoked
                continue;
            }
        }

        return new RelationGraph($relations);
    }

    /**
     * Convert Eloquent relation to RelationDescriptor.
     */
    protected function convertEloquentRelation(string $name, $eloquentRelation): ?RelationDescriptor
    {
        $relatedModel = get_class($eloquentRelation->getRelated());

        if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::BelongsTo,
                targetModel: $relatedModel,
                keys: [
                    'foreign' => $eloquentRelation->getForeignKeyName(),
                    'owner' => $eloquentRelation->getOwnerKeyName(),
                ],
            );
        }

        if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::HasMany,
                targetModel: $relatedModel,
                keys: [
                    'foreign' => $eloquentRelation->getForeignKeyName(),
                    'local' => $eloquentRelation->getLocalKeyName(),
                ],
            );
        }

        if ($eloquentRelation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            return new RelationDescriptor(
                name: $name,
                type: RelationType::BelongsToMany,
                targetModel: $relatedModel,
                keys: [
                    'foreign' => $eloquentRelation->getForeignPivotKeyName(),
                    'related' => $eloquentRelation->getRelatedPivotKeyName(),
                ],
                pivot: $eloquentRelation->getTable(),
            );
        }

        // HasOne, MorphTo, etc. - add as needed
        return null;
    }

    /**
     * Introspect dimensions from model casts.
     */
    protected function introspectDimensions(Model $model): DimensionCatalog
    {
        $dimensions = [];
        $casts = $model->getCasts();

        foreach ($casts as $column => $castType) {
            // Skip appended attributes (not real columns)
            if (in_array($column, $model->getAppends())) {
                continue;
            }

            // datetime/date → TimeDimension
            if (in_array($castType, ['datetime', 'date', 'timestamp', 'immutable_datetime', 'immutable_date'])) {
                $precision = in_array($castType, ['date', 'immutable_date']) ? 'date' : 'timestamp';
                $dimensions[TimeDimension::class . '::' . $column] = TimeDimension::make($column)
                    ->precision($precision);
            }

            // enum → Could support EnumDimension (future)
            // Add more cast → dimension mappings as needed
        }

        // Apply column name heuristics from config
        $patterns = config('slice.dimensions.column_patterns', []);
        foreach ($patterns as $pattern => $dimensionClass) {
            foreach ($model->getFillable() as $column) {
                if ($this->matchesPattern($column, $pattern)) {
                    $dimensions[$dimensionClass . '::' . $column] = new $dimensionClass($column);
                }
            }
        }

        return new DimensionCatalog($dimensions);
    }

    protected function introspectPrimaryKey(Model $model): PrimaryKeyDescriptor
    {
        $key = $model->getKeyName();

        // Handle composite keys (if model defines it)
        if (is_array($key)) {
            return new PrimaryKeyDescriptor(
                columns: $key,
                autoIncrement: false,
            );
        }

        return new PrimaryKeyDescriptor(
            columns: [$key],
            autoIncrement: $model->getIncrementing(),
        );
    }

    protected function hasSoftDeletes(string $modelClass): bool
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass));
    }

    protected function findByTableName(string $tableName): ?ModelMetadata
    {
        foreach ($this->models as $metadata) {
            if ($metadata->tableName === $tableName) {
                return $metadata;
            }
        }

        return null;
    }

    protected function ensureScanned(): void
    {
        if (!$this->scanned) {
            $this->scan();
        }
    }

    // CachableSchemaProvider implementation
    public function cacheKey(): string
    {
        return 'eloquent_schema_' . md5(serialize($this->namespaces));
    }

    public function toCache(): array
    {
        return [
            'models' => array_map(fn($m) => $m->toArray(), $this->models),
            'scanned_at' => now()->timestamp,
        ];
    }

    public function fromCache(array $cached): void
    {
        foreach ($cached['models'] as $modelClass => $data) {
            $this->models[$modelClass] = ModelMetadata::fromArray($data);
        }
        $this->scanned = true;
    }

    public function isCacheValid(): bool
    {
        // Check if any model files have changed
        $cacheTime = $this->cache->get($this->cacheKey())['scanned_at'] ?? 0;

        foreach ($this->namespaces as $namespace) {
            $path = $this->namespaceToPath($namespace);
            if ($this->directoryModifiedAfter($path, $cacheTime)) {
                return false;
            }
        }

        return true;
    }

    // Helper methods
    protected function namespaceToPath(string $namespace): string
    {
        return app_path(str_replace('App\\', '', str_replace('\\', '/', $namespace)));
    }

    protected function pathToClass(string $path, string $namespace): string
    {
        $relativePath = str_replace(app_path(), '', $path);
        $relativePath = str_replace('.php', '', $relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);

        return 'App' . $relativePath;
    }

    protected function directoryModifiedAfter(string $directory, int $timestamp): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getMTime() > $timestamp) {
                return true;
            }
        }

        return false;
    }

    protected function isEloquentRelationType(string $typeName): bool
    {
        return in_array($typeName, [
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            \Illuminate\Database\Eloquent\Relations\HasOne::class,
            \Illuminate\Database\Eloquent\Relations\BelongsToMany::class,
            \Illuminate\Database\Eloquent\Relations\MorphTo::class,
            \Illuminate\Database\Eloquent\Relations\MorphMany::class,
            \Illuminate\Database\Eloquent\Relations\MorphOne::class,
        ]);
    }

    protected function matchesPattern(string $column, string $pattern): bool
    {
        $regex = str_replace('*', '.*', preg_quote($pattern, '/'));
        return preg_match('/^' . $regex . '$/', $column);
    }
}
```

### 3. Example: ClickHouseProvider

**Purpose:** Demonstrate third-party provider implementation

```php
namespace Vendor\SliceClickHouse;

class ClickHouseProvider implements CachableSchemaProvider
{
    protected string $connection;
    protected array $tables = [];
    protected bool $introspected = false;

    public function __construct(string $connection = 'clickhouse')
    {
        $this->connection = $connection;
    }

    public function boot(SchemaCache $cache): void
    {
        if ($cache->has($this->cacheKey())) {
            $this->fromCache($cache->get($this->cacheKey()));
        } else {
            $this->introspect();
            $cache->put($this->cacheKey(), $this->toCache());
        }
    }

    /**
     * Introspect ClickHouse system tables.
     */
    protected function introspect(): void
    {
        $db = DB::connection($this->connection);

        // Query system.tables
        $tables = $db->table('system.tables')
            ->where('database', $db->getDatabaseName())
            ->get();

        foreach ($tables as $table) {
            $metadata = $this->introspectTable($table->name);
            $this->tables[$table->name] = $metadata;
        }

        $this->introspected = true;
    }

    protected function introspectTable(string $tableName): TableMetadata
    {
        $db = DB::connection($this->connection);

        // Get columns from system.columns
        $columns = $db->table('system.columns')
            ->where('database', $db->getDatabaseName())
            ->where('table', $tableName)
            ->get();

        // Build dimension catalog from column types
        $dimensions = [];
        foreach ($columns as $column) {
            if (str_contains($column->type, 'DateTime')) {
                $dimensions[TimeDimension::class . '::' . $column->name] =
                    TimeDimension::make($column->name)->asTimestamp();
            }
        }

        return new TableMetadata(
            name: $tableName,
            connection: $this->connection,
            dimensionCatalog: new DimensionCatalog($dimensions),
            relationGraph: new RelationGraph([]), // ClickHouse doesn't have FK relations
            primaryKey: $this->detectPrimaryKey($tableName),
        );
    }

    public function tables(): iterable
    {
        foreach ($this->tables as $metadata) {
            yield new MetadataBackedTable($metadata);
        }
    }

    public function provides(string $identifier): bool
    {
        return isset($this->tables[$identifier]);
    }

    public function resolveMetricSource(string $reference): MetricSource
    {
        [$tableName, $column] = explode('.', $reference, 2);

        if (!isset($this->tables[$tableName])) {
            throw new InvalidArgumentException("ClickHouse table '{$tableName}' not found");
        }

        return new MetricSource(
            table: new MetadataBackedTable($this->tables[$tableName]),
            column: $column,
            connection: $this->connection
        );
    }

    public function relations(string $table): RelationGraph
    {
        return new RelationGraph([]); // ClickHouse doesn't have FK relations
    }

    public function dimensions(string $table): DimensionCatalog
    {
        return $this->tables[$table]->dimensionCatalog;
    }

    public function priority(): int
    {
        return 300; // Lower priority than Eloquent
    }

    public function name(): string
    {
        return 'clickhouse';
    }

    // Caching implementation...
    public function cacheKey(): string
    {
        return 'clickhouse_schema_' . $this->connection;
    }

    public function toCache(): array
    {
        return [
            'tables' => array_map(fn($t) => $t->toArray(), $this->tables),
            'introspected_at' => now()->timestamp,
        ];
    }

    public function fromCache(array $cached): void
    {
        foreach ($cached['tables'] as $tableName => $data) {
            $this->tables[$tableName] = TableMetadata::fromArray($data);
        }
        $this->introspected = true;
    }

    public function isCacheValid(): bool
    {
        // ClickHouse schemas change rarely; cache for 24 hours
        $cacheTime = $this->cache->get($this->cacheKey())['introspected_at'] ?? 0;
        return (now()->timestamp - $cacheTime) < 86400;
    }
}
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)

**Goal:** Create provider infrastructure

**Tasks:**

1. **Create SchemaProvider contract** (2 days)
   - Define `SchemaProvider` interface
   - Define `CachableSchemaProvider` interface
   - Create `SchemaCache` abstraction
   - Documentation for provider authors

2. **Create SchemaProviderManager** (3 days)
   - Implement provider registration
   - Implement resolution priority
   - Implement metric source parsing
   - Unit tests for priority resolution

3. **Create ManualTableProvider** (2 days)
   - Wrap existing `Table` classes
   - Ensure 100% backward compatibility
   - Tests with existing workbench tables

4. **Create SliceSource** (1 day)
   - Define interface
   - Create `ManualTableAdapter` (wraps old Table)
   - Create `MetadataBackedTable` (wraps provider metadata)

**Deliverables:**
- ✅ Provider infrastructure complete
- ✅ Existing Table classes work via ManualTableProvider
- ✅ Zero breaking changes
- ✅ 100% test coverage for manager

---

### Phase 2: Eloquent Provider (Week 3-4)

**Goal:** Ship EloquentSchemaProvider

**Tasks:**

1. **Metadata extraction** (5 days)
   - Model scanner (recursive namespace scanning)
   - Relation introspection via Reflection
   - Cast → Dimension mapping
   - Primary key detection
   - Soft delete detection

2. **Caching implementation** (2 days)
   - Implement `CachableSchemaProvider`
   - File-based cache storage
   - Cache invalidation (file mtime)
   - Artisan commands (`slice:schema-cache`, `slice:schema-clear`)

3. **Testing** (3 days)
   - Unit tests for relation introspection
   - Unit tests for dimension mapping
   - Integration tests with real models
   - Cache invalidation tests

**Deliverables:**
- ✅ EloquentSchemaProvider fully functional
- ✅ Automatic relation detection
- ✅ Automatic dimension detection
- ✅ Production-ready caching (< 50ms)

---

### Phase 3: Query Engine Integration (Week 5)

**Goal:** Update query engine to consume providers

**Tasks:**

1. **Update Aggregation classes** (2 days)
   - Inject `SchemaProviderManager`
   - Use `parseMetricSource()` instead of Registry
   - Maintain backward compat with existing metrics

2. **Update QueryBuilder** (2 days)
   - Accept `SliceSource` instead of `Table`
   - Update all engine components (JoinResolver, DimensionResolver)
   - Tests ensuring both manual and Eloquent tables work

3. **Update Slice facade** (1 day)
   - Integrate SchemaProviderManager
   - Provider registration API
   - Documentation updates

**Deliverables:**
- ✅ End-to-end queries work with Eloquent provider
- ✅ Mixed manual/Eloquent queries work
- ✅ All existing tests pass

---

### Phase 4: Base Table Resolution (Week 6)

**Goal:** Smart GROUP BY base table detection

**Tasks:**

1. **Create BaseTableResolver** (3 days)
   - Implement scoring algorithm
   - Metric density calculation
   - Dimension coverage analysis
   - Relationship depth detection
   - Explicit `->baseTable()` / `->baseModel()` support

2. **Grain-aware aggregation** (2 days)
   - Per-metric grain specification (`->grain('parent')`)
   - hasMany roll-up strategies
   - Double-counting prevention

3. **Testing** (2 days)
   - Single table queries
   - Parent/child queries
   - Multi-table ambiguous queries
   - Explicit base table specification

**Deliverables:**
- ✅ Automatic base table detection (90% of cases)
- ✅ Explicit override for complex scenarios
- ✅ Clear error messages for ambiguous cases

---

### Phase 5: Relation Path Walker (Week 7)

**Goal:** Multi-hop filters across relations

**Tasks:**

1. **Create RelationPathWalker** (3 days)
   - Parse dot notation (`customer.country`, `items.product.category`)
   - Multi-hop join path resolution
   - Polymorphic relation support

2. **Filter DSL** (2 days)
   - `->whereRelation()` API
   - EXISTS vs JOIN strategies
   - Integration with QueryBuilder

3. **Testing** (2 days)
   - Single-hop filters
   - Multi-hop filters
   - Polymorphic relations
   - Performance tests

**Deliverables:**
- ✅ Multi-hop relation filters work
- ✅ Polymorphic relations supported
- ✅ Performance acceptable

---

### Phase 6: Documentation & Tooling (Week 8)

**Goal:** Complete documentation and migration guide

**Tasks:**

1. **Provider author guide** (2 days)
   - How to implement SchemaProvider
   - ClickHouse provider example
   - Caching best practices

2. **Migration guide** (2 days)
   - Step-by-step migration from Table classes
   - API comparison (before/after)
   - Common pitfalls

3. **Artisan commands** (1 day)
   - `slice:schema-cache` - Build cache
   - `slice:schema-clear` - Clear cache
   - `slice:schema-profile` - Performance profiling
   - Hook into `artisan optimize`

4. **Video tutorials** (2 days)
   - Quick start with Eloquent
   - Creating custom providers
   - Migration walkthrough

**Deliverables:**
- ✅ Complete documentation
- ✅ Migration guide
- ✅ Provider author guide
- ✅ Video tutorials

---

## API Design

### Provider Registration

**Config-based:**

```php
// config/slice.php
return [
    'providers' => [
        \NickPotts\Slice\Providers\ManualTableProvider::class,
        \NickPotts\Slice\Providers\EloquentSchemaProvider::class,
        // Third-party providers
        \Vendor\SliceClickHouse\ClickHouseProvider::class,
    ],

    'eloquent' => [
        'namespaces' => ['App\\Models'],
        'cache' => true,
    ],
];
```

**Runtime registration:**

```php
use NickPotts\Slice\Support\SchemaProviderManager;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $manager = app(SchemaProviderManager::class);

        // Register custom provider
        $manager->register(new CustomProvider());

        // Override specific table
        $manager->override('orders', new ClickHouseProvider());

        // Register explicit table (highest priority)
        $manager->registerTable(new CustomOrdersTable());
    }
}
```

### Before & After Comparison

**Before (Manual Table classes):**

```php
// Define OrdersTable (~20 lines)
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
        ];
    }
}

// Define OrdersMetric enum (~15 lines)
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

**After (Eloquent Provider):**

```php
// Order model (already exists in app)
class Order extends Model {
    protected $casts = [
        'created_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo {
        return $this->belongsTo(Customer::class);
    }
}

// Usage - ZERO additional code
Slice::query()
    ->metrics([Sum::make('orders.total')->currency('USD')])
    ->get();

// Or with model class reference
Slice::query()
    ->metrics([Sum::make(Order::class.'::total')->currency('USD')])
    ->get();
```

**After (Custom ClickHouse Provider):**

```php
// In service provider
$manager->register(new ClickHouseProvider('clickhouse'));

// Usage - same API!
Slice::query()
    ->metrics([Sum::make('clickhouse:events.count')])
    ->dimensions([TimeDimension::make('events.timestamp')->hourly()])
    ->get();
```

### Mixed Providers

```php
// Orders from Eloquent, Events from ClickHouse, CustomData from Manual table
Slice::query()
    ->metrics([
        Sum::make('orders.total')->currency('USD'),           // EloquentSchemaProvider
        Sum::make('clickhouse:events.count'),                 // ClickHouseProvider
        Count::make('custom_data.records'),                   // ManualTableProvider
    ])
    ->get();
```

### Base Table Specification

```php
// Automatic (heuristic-based)
Slice::query()
    ->metrics([
        Sum::make('orders.total'),
        Sum::make('order_items.quantity'),
    ])
    ->dimensions([TimeDimension::make('orders.created_at')->daily()])
    ->get(); // Base: 'orders' (from dimension)

// Explicit (override automatic)
Slice::query()
    ->baseTable('orders')
    ->metrics([
        Sum::make('orders.total'),
        Sum::make('order_items.quantity'),
    ])
    ->get(); // Base: 'orders' (explicit)

// Model class
Slice::query()
    ->baseModel(Order::class)
    ->metrics([...])
    ->get();
```

### Relation Filters

```php
// Single-hop
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->whereRelation('customer.country', '=', 'US')
    ->get();

// Multi-hop
Slice::query()
    ->metrics([Sum::make('orders.total')])
    ->whereRelation('items.product.category', '=', 'Electronics')
    ->get();
```

---

## Edge Case Handling

### 1. Provider Priority Conflicts

**Challenge:** Two providers claim same table

```php
ManualTableProvider: provides('orders') → true
EloquentSchemaProvider: provides('orders') → true
```

**Solution:** Priority system (lower number = higher priority)

```php
ManualTableProvider::priority() → 100
EloquentSchemaProvider::priority() → 200

// Result: ManualTableProvider wins
```

**Override:** Explicit table registration or provider override

```php
$manager->registerTable(new CustomOrdersTable()); // Highest priority
$manager->override('orders', new ClickHouseProvider()); // Second highest
```

---

### 2. Multiple Database Connections

**Challenge:** Same table name on different connections

```php
Order::class // Connection: 'mysql'
ArchivedOrder::class // Connection: 'archive_db', table: 'orders'
```

**Solution:** Connection-qualified notation

```php
Sum::make('mysql:orders.total')
Sum::make('archive_db:orders.total')

// Or model class reference
Sum::make(Order::class.'::total')
Sum::make(ArchivedOrder::class.'::total')
```

**Validation:** Prevent cross-connection joins

```php
if (count(array_unique($connections)) > 1) {
    throw new CrossConnectionJoinException(
        'Cannot join tables across different database connections. ' .
        'Available strategies: separate queries or software joins.'
    );
}
```

---

### 3. Composite Primary Keys

**Challenge:** Model has composite PK

```php
class Enrollment extends Model {
    protected $primaryKey = ['student_id', 'course_id'];
    public $incrementing = false;
}
```

**Solution:** PrimaryKeyDescriptor supports multiple columns

```php
new PrimaryKeyDescriptor(
    columns: ['student_id', 'course_id'],
    autoIncrement: false,
)
```

**Impact on base table:** Requires explicit specification

```php
Slice::query()
    ->baseTable('enrollments') // Must be explicit
    ->metrics([...])
    ->get();
```

---

### 4. Polymorphic Relations

**Challenge:** MorphTo/MorphMany relations

```php
class Comment extends Model {
    public function commentable(): MorphTo {
        return $this->morphTo();
    }
}
```

**Solution:** Requires morph map and type specification

```php
// Provider introspects morph relations
new RelationDescriptor(
    name: 'commentable',
    type: RelationType::MorphTo,
    morph: [
        'type_column' => 'commentable_type',
        'id_column' => 'commentable_id',
        'map' => Relation::$morphMap,
    ],
);

// Filter requires type specification (future enhancement)
->whereRelation('commentable(Post).title', 'LIKE', '%Laravel%')
```

---

### 5. Soft Deletes & Global Scopes

**Challenge:** Should soft-deleted records be filtered?

**Decision:** Provider flags soft-deletable tables, QueryBuilder applies filter by default

```php
class ModelMetadata {
    public bool $softDeletes;
}

// QueryBuilder
if ($table->metadata()->softDeletes && !$this->withTrashed) {
    $query->whereNull($tableName . '.deleted_at');
}

// Override
Slice::query()
    ->withTrashed()
    ->metrics([...])
    ->get();
```

**Global scopes:** Skip by default (analytics needs raw data)

```php
// Provider uses newInstanceWithoutConstructor to avoid scopes
$model = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();
```

---

### 6. Provider Cache Invalidation

**Challenge:** When to invalidate cached schema?

**Strategies:**

1. **File mtime-based** (development):
   ```php
   public function isCacheValid(): bool {
       $cacheTime = $this->cache->timestamp();
       return !$this->directoryModifiedAfter(app_path('Models'), $cacheTime);
   }
   ```

2. **Manual invalidation** (production):
   ```bash
   php artisan slice:schema-clear
   php artisan slice:schema-cache
   ```

3. **Auto-refresh in local/testing**:
   ```php
   if (app()->environment(['local', 'testing'])) {
       $provider->scan(); // Always fresh in dev
   } else {
       $provider->loadCache(); // Use cache in production
   }
   ```

---

### 7. Non-Eloquent Data Sources

**Challenge:** ClickHouse, HTTP APIs don't have Eloquent models

**Solution:** Custom providers implement SchemaProvider

**Example: HTTP API Provider**

```php
class OpenAPIProvider implements SchemaProvider
{
    protected string $schemaUrl;

    public function __construct(string $schemaUrl)
    {
        $this->schemaUrl = $schemaUrl;
    }

    public function boot(SchemaCache $cache): void
    {
        $schema = Http::get($this->schemaUrl)->json();
        $this->parseOpenAPISchema($schema);
    }

    protected function parseOpenAPISchema(array $schema): void
    {
        foreach ($schema['components']['schemas'] as $name => $definition) {
            // Build SliceSource from OpenAPI schema
            $this->tables[$name] = new MetadataBackedTable(
                new TableMetadata(
                    name: $name,
                    connection: 'api',
                    dimensionCatalog: $this->parseDimensions($definition),
                    // ...
                )
            );
        }
    }

    // Implement SchemaProvider methods...
}
```

---

## Testing Strategy

### Unit Tests

#### SchemaProviderManager Tests

```php
describe('SchemaProviderManager', function () {
    it('resolves from highest priority provider', function () {
        $manager = new SchemaProviderManager();

        $manual = new ManualTableProvider(); // Priority: 100
        $manual->register(new OrdersTable());

        $eloquent = new EloquentSchemaProvider(); // Priority: 200
        // (has Order model)

        $manager->register($manual);
        $manager->register($eloquent);

        $table = $manager->resolve('orders');

        expect($table)->toBeInstanceOf(ManualTableAdapter::class); // Manual wins
    });

    it('falls back to lower priority providers', function () {
        $manager = new SchemaProviderManager();

        $eloquent = new EloquentSchemaProvider();
        $manager->register($eloquent);

        $table = $manager->resolve('orders');

        expect($table)->toBeInstanceOf(MetadataBackedTable::class); // Eloquent fallback
    });

    it('throws when table not found in any provider', function () {
        $manager = new SchemaProviderManager();
        $manager->register(new ManualTableProvider());

        expect(fn() => $manager->resolve('nonexistent'))
            ->toThrow(TableNotFoundException::class);
    });

    it('parses metric source notation', function () {
        $manager = new SchemaProviderManager();
        $eloquent = new EloquentSchemaProvider();
        $manager->register($eloquent);

        $source = $manager->parseMetricSource('orders.total');

        expect($source->table->name())->toBe('orders')
            ->and($source->column)->toBe('total');
    });

    it('parses connection-qualified notation', function () {
        $manager = new SchemaProviderManager();
        $eloquent = new EloquentSchemaProvider();
        $manager->register($eloquent);

        $source = $manager->parseMetricSource('mysql:orders.total');

        expect($source->connection)->toBe('mysql')
            ->and($source->table->name())->toBe('orders');
    });

    it('parses model class notation', function () {
        $manager = new SchemaProviderManager();
        $eloquent = new EloquentSchemaProvider();
        $manager->register($eloquent);

        $source = $manager->parseMetricSource(Order::class.'::total');

        expect($source->table->modelClass())->toBe(Order::class)
            ->and($source->column)->toBe('total');
    });
});
```

#### EloquentSchemaProvider Tests

```php
describe('EloquentSchemaProvider', function () {
    it('scans configured namespaces', function () {
        $provider = new EloquentSchemaProvider(['App\\Models']);
        $provider->boot(new SchemaCache());

        $tables = iterator_to_array($provider->tables());

        expect($tables)->toHaveCount(5); // Order, Customer, OrderItem, Product, User
    });

    it('introspects BelongsTo relations', function () {
        $provider = new EloquentSchemaProvider();
        $provider->boot(new SchemaCache());

        $relations = $provider->relations('orders');

        expect($relations->has('customer'))->toBeTrue()
            ->and($relations->get('customer')->type)->toBe(RelationType::BelongsTo)
            ->and($relations->get('customer')->targetModel)->toBe(Customer::class);
    });

    it('introspects HasMany relations', function () {
        $provider = new EloquentSchemaProvider();
        $provider->boot(new SchemaCache());

        $relations = $provider->relations('orders');

        expect($relations->has('items'))->toBeTrue()
            ->and($relations->get('items')->type)->toBe(RelationType::HasMany);
    });

    it('introspects datetime casts as TimeDimensions', function () {
        $provider = new EloquentSchemaProvider();
        $provider->boot(new SchemaCache());

        $dimensions = $provider->dimensions('orders');

        expect($dimensions->has(TimeDimension::class.'::created_at'))->toBeTrue()
            ->and($dimensions->get(TimeDimension::class.'::created_at'))->toBeInstanceOf(TimeDimension::class);
    });

    it('caches introspection results', function () {
        $cache = new SchemaCache();
        $provider = new EloquentSchemaProvider();
        $provider->boot($cache);

        // First boot: scans models
        $firstBootTime = microtime(true);
        $provider->boot($cache);
        $firstTime = microtime(true) - $firstBootTime;

        // Second boot: loads from cache
        $secondBootTime = microtime(true);
        $provider2 = new EloquentSchemaProvider();
        $provider2->boot($cache);
        $secondTime = microtime(true) - $secondBootTime;

        expect($secondTime)->toBeLessThan($firstTime * 0.1); // 10x faster
    });
});
```

### Integration Tests

```php
test('end-to-end query with Eloquent provider', function () {
    // No manual Table classes defined

    $results = Slice::query()
        ->metrics([
            Sum::make('orders.total')->currency('USD'),
            Count::make('orders.id'),
            Sum::make('order_items.quantity'),
        ])
        ->dimensions([TimeDimension::make('orders.created_at')->daily()])
        ->get();

    expect($results)->not->toBeEmpty()
        ->and($results->first())->toHaveKeys([
            'orders_total',
            'orders_id',
            'order_items_quantity',
            'orders_created_at_day',
        ]);
});

test('mixed manual and Eloquent providers', function () {
    // OrdersTable manually defined
    // Customer model auto-detected

    $results = Slice::query()
        ->metrics([
            OrdersMetric::Revenue, // ManualTableProvider
            Count::make('customers.id'), // EloquentSchemaProvider
        ])
        ->get();

    expect($results)->not->toBeEmpty();
});

test('provider priority resolution', function () {
    // Both providers have 'orders', manual should win

    $results = Slice::query()
        ->metrics([Sum::make('orders.total')])
        ->get();

    // Verify ManualTableProvider was used (check internals or SQL)
    $sql = DB::getQueryLog()[0]['query'];
    expect($sql)->toContain('FROM orders');
});
```

### Backward Compatibility Tests

```php
test('existing Table classes still work', function () {
    // Use existing workbench OrdersTable and OrdersMetric

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

test('all existing Pest tests pass', function () {
    // Run entire existing test suite with providers enabled
    // Should pass without modification
});
```

### Provider Author Tests

```php
test('custom provider can be registered', function () {
    $manager = app(SchemaProviderManager::class);
    $custom = new CustomProvider();

    $manager->register($custom);

    $table = $manager->resolve('custom_table');

    expect($table->name())->toBe('custom_table');
});

test('third-party ClickHouse provider works', function () {
    $manager = app(SchemaProviderManager::class);
    $manager->register(new ClickHouseProvider());

    $results = Slice::query()
        ->metrics([Sum::make('clickhouse:events.count')])
        ->get();

    expect($results)->not->toBeEmpty();
});
```

---

## Performance Analysis

### Introspection Overhead

| Operation | Time (Uncached) | Time (Cached) | Method |
|-----------|-----------------|---------------|--------|
| Scan 100 models | 300-500ms | 5-10ms | File cache |
| Introspect 1 model | 5-10ms | <1ms | In-memory |
| Resolve provider | N/A | <1ms | Array lookup |
| Parse metric source | N/A | <1ms | String parsing |
| **Total query overhead** | **400-600ms** | **< 20ms** | Production cache |

### Caching Strategy

**Development:**
```php
// Auto-scan on every request (or file watcher)
if (app()->environment('local')) {
    $provider->scan();
}
```

**Production:**
```php
// Load from cache file
$provider->boot($cache); // Loads from bootstrap/cache/slice-schema.php
```

**Cache Invalidation:**
```bash
# Manual (deployment script)
php artisan slice:schema-cache

# Automatic (artisan optimize)
php artisan optimize # Calls slice:schema-cache automatically
```

### Query Planning Performance

| Metric | Current | With Providers | Impact |
|--------|---------|----------------|--------|
| Table lookup | O(1) registry | O(p) providers | p = 2-5 typically |
| Relation resolution | O(1) | O(1) cached | None |
| Dimension resolution | O(1) | O(1) cached | None |
| Join path BFS | O(V+E) | O(V+E) | None |

**Overall:** Negligible impact (< 5ms) with caching

---

## Migration Guide

### Step 1: Update Package

```bash
composer update nick-potts/slice
```

All existing code works unchanged.

### Step 2: Publish Config

```bash
php artisan vendor:publish --tag=slice-config
```

```php
// config/slice.php
return [
    'providers' => [
        \NickPotts\Slice\Providers\ManualTableProvider::class,
        \NickPotts\Slice\Providers\EloquentSchemaProvider::class,
    ],

    'eloquent' => [
        'namespaces' => ['App\\Models'],
        'cache' => env('SLICE_CACHE', true),
    ],
];
```

### Step 3: Warm Schema Cache

```bash
php artisan slice:schema-cache
```

This scans your models and caches metadata.

### Step 4: Test Existing Queries

Run your existing analytics queries. They should work unchanged via `ManualTableProvider`.

### Step 5: Try Direct Aggregations

Add a new metric using direct aggregation:

```php
// Before: Would need to create Table + Enum
// After: Just write the query
Slice::query()
    ->metrics([
        Sum::make('orders.shipping_cost')->currency('USD'), // NEW
        OrdersMetric::Revenue, // Existing enum still works
    ])
    ->get();
```

### Step 6: Gradually Remove Table Classes (Optional)

**Can remove Table class if:**
- ✅ Table name matches Eloquent model
- ✅ Relations defined in Eloquent model
- ✅ Dimensions auto-detectable from casts

**Must keep Table class if:**
- ❌ Non-Eloquent data source (ClickHouse, API)
- ❌ Complex custom dimensions
- ❌ CrossJoin relations (no FK)

**Migration Example:**

```php
// BEFORE: OrdersTable.php (can be deleted)
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
        ];
    }
}

// AFTER: Order.php (already exists)
class Order extends Model {
    protected $casts = [
        'created_at' => 'datetime', // Auto-detected as TimeDimension
    ];

    public function customer(): BelongsTo {
        return $this->belongsTo(Customer::class); // Auto-detected relation
    }
}

// Delete OrdersTable.php ✅
```

### Step 7: Add Custom Providers (Optional)

```php
// AppServiceProvider
public function boot()
{
    app(SchemaProviderManager::class)
        ->register(new ClickHouseProvider('clickhouse'));
}

// Usage
Slice::query()
    ->metrics([Sum::make('clickhouse:events.count')])
    ->get();
```

---

## Provider Development Guide

### Creating a Custom Provider

**1. Implement SchemaProvider:**

```php
class MyCustomProvider implements SchemaProvider
{
    public function boot(SchemaCache $cache): void
    {
        // Initialize: load schema, connect to data source, etc.
    }

    public function tables(): iterable
    {
        // Yield SliceSource instances for all tables
    }

    public function provides(string $identifier): bool
    {
        // Return true if this provider can supply the table
    }

    public function resolveMetricSource(string $reference): MetricSource
    {
        // Parse reference and return MetricSource
    }

    public function relations(string $table): RelationGraph
    {
        // Return relation metadata
    }

    public function dimensions(string $table): DimensionCatalog
    {
        // Return dimension metadata
    }

    public function priority(): int
    {
        return 300; // Lower priority than built-in providers
    }

    public function name(): string
    {
        return 'my-custom-provider';
    }
}
```

**2. (Optional) Add Caching:**

```php
class MyCustomProvider implements CachableSchemaProvider
{
    public function cacheKey(): string
    {
        return 'my_custom_provider_schema';
    }

    public function toCache(): array
    {
        return ['tables' => $this->tables, 'cached_at' => now()->timestamp];
    }

    public function fromCache(array $cached): void
    {
        $this->tables = $cached['tables'];
    }

    public function isCacheValid(): bool
    {
        // Check if cache is still valid
        return true;
    }
}
```

**3. Register Provider:**

```php
// config/slice.php
return [
    'providers' => [
        \NickPotts\Slice\Providers\ManualTableProvider::class,
        \NickPotts\Slice\Providers\EloquentSchemaProvider::class,
        \Vendor\Package\MyCustomProvider::class,
    ],
];

// Or runtime
app(SchemaProviderManager::class)->register(new MyCustomProvider());
```

**4. Publish Package:**

```bash
# Create package
composer create-project laravel/laravel slice-my-provider --prefer-dist

# Implement provider
# Publish to Packagist

# Users install
composer require vendor/slice-my-provider
```

---

## Conclusion

This provider-agnostic refactor will:

1. ✅ **Reduce boilerplate by 90%+** for Eloquent users
2. ✅ **Enable any data source** via pluggable providers
3. ✅ **Maintain 100% backward compatibility** with existing Table classes
4. ✅ **Ship Eloquent provider** out-of-the-box
5. ✅ **Support community extensions** (ClickHouse, OpenAPI, etc.)
6. ✅ **Cache for production** with < 20ms overhead
7. ✅ **Phase implementation** over 8 weeks

**Key Architectural Shifts:**

- **Table classes:** Required → Optional (via ManualTableProvider)
- **Schema source:** Single → Pluggable (via SchemaProvider)
- **Discovery:** Manual → Automatic (for Eloquent)
- **Extensibility:** Core changes → Plugin system

**Next Steps:**
1. Review this design with stakeholders
2. Create feature branch
3. Begin Phase 1: Provider infrastructure
4. Iterate based on feedback

**Success Metrics:**
- Zero breaking changes in existing tests
- 90%+ boilerplate reduction for Eloquent users
- 5+ community providers within 6 months
- < 50ms production overhead (cached)

---

**Document Status:** Ready for Review
**Estimated Effort:** 8 weeks (1 developer)
**Complexity:** High
**Value:** Very High

**Architecture:** Provider-Agnostic, Pluggable, Extensible
