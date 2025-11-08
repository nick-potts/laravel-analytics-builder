<?php

namespace NickPotts\Slice\Providers\Eloquent;

use NickPotts\Slice\Contracts\CachableSchemaProvider;
use NickPotts\Slice\Schemas\Dimensions\DimensionCatalog;
use NickPotts\Slice\Schemas\MetadataBackedTable;
use NickPotts\Slice\Schemas\ModelMetadata;
use NickPotts\Slice\Schemas\Relations\RelationGraph;
use NickPotts\Slice\Support\Cache\SchemaCache;
use NickPotts\Slice\Support\MetricSource;

/**
 * Auto-introspects Laravel Eloquent models to discover table schemas.
 *
 * Configuration loaded from config/slice.php:eloquent.model_directories
 */
class EloquentSchemaProvider implements CachableSchemaProvider
{
    /** @var array<string, string> Map of directories to namespace prefixes */
    private array $directories = [];

    /** @var array<string, ModelMetadata> Discovered models (keyed by model class) */
    private array $models = [];

    /** @var array<string, ModelMetadata> Discovered models indexed by table name */
    private array $tableIndex = [];

    private bool $scanned = false;

    private SchemaCache $cache;

    private ModelScanner $scanner;

    private ModelIntrospector $introspector;

    /**
     * @param  array<string, string>|null  $directories  Map of directories to namespace prefixes
     */
    public function __construct(?array $directories = null)
    {
        $this->scanner = new ModelScanner();
        $this->introspector = new ModelIntrospector(
            new Introspectors\PrimaryKeyIntrospector(),
            new Introspectors\RelationIntrospector(),
            new Introspectors\DimensionIntrospector(),
        );

        if ($directories === null) {
            $directories = $this->loadDirectoriesFromConfig();
        }

        $this->directories = $directories;
    }

    public function boot(SchemaCache $cache): void
    {
        $this->cache = $cache;

        if ($cache->has($this->cacheKey()) && $this->isCacheValid()) {
            $this->fromCache($cache->get($this->cacheKey()));
        } else {
            $this->scan();
            if ($cache->isEnabled()) {
                $cache->put($this->cacheKey(), $this->toCache());
            }
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
        if (isset($this->tableIndex[$identifier])) {
            return true;
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
            $metadata = $this->tableIndex[$tableName] ?? null;
        }

        if (!$metadata) {
            throw new \InvalidArgumentException("Model for '{$reference}' not found");
        }

        return new MetricSource(
            table: new MetadataBackedTable($metadata),
            column: $column,
            connection: $metadata->connection
        );
    }

    public function relations(string $table): RelationGraph
    {
        $this->ensureScanned();

        $metadata = $this->tableIndex[$table] ?? null;
        if (!$metadata) {
            return new RelationGraph();
        }

        return $metadata->relationGraph;
    }

    public function dimensions(string $table): DimensionCatalog
    {
        $this->ensureScanned();

        $metadata = $this->tableIndex[$table] ?? null;
        if (!$metadata) {
            return new DimensionCatalog();
        }

        return $metadata->dimensionCatalog;
    }

    public function name(): string
    {
        return 'eloquent';
    }

    // ===== CachableSchemaProvider Implementation =====

    public function cacheKey(): string
    {
        return 'eloquent_schema_' . md5(serialize($this->directories));
    }

    public function toCache(): array
    {
        return [
            'directories' => $this->directories,
            'models' => array_map(fn(ModelMetadata $m) => $m->toArray(), $this->models),
            'cached_at' => time(),
        ];
    }

    public function fromCache(array $cached): void
    {
        $this->directories = $cached['directories'] ?? $this->directories;

        foreach ($cached['models'] ?? [] as $modelClass => $data) {
            $metadata = ModelMetadata::fromArray($data);
            $this->models[$modelClass] = $metadata;
            $this->tableIndex[$metadata->tableName] = $metadata;
        }

        $this->scanned = true;
    }

    public function isCacheValid(): bool
    {
        $cacheData = $this->cache->get($this->cacheKey());
        if (!$cacheData) {
            return false;
        }

        $cacheTime = $cacheData['cached_at'] ?? 0;

        foreach ($this->directories as $directory => $namespace) {
            if ($this->directoryModifiedAfter($directory, $cacheTime)) {
                return false;
            }
        }

        return true;
    }

    // ===== Private Methods =====

    private function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        foreach ($this->directories as $directory => $namespace) {
            $this->scanDirectory($directory, $namespace);
        }

        $this->scanned = true;
    }

    private function scanDirectory(string $directory, string $namespace): void
    {
        $modelClasses = $this->scanner->scan($directory, $namespace);

        foreach ($modelClasses as $modelClass) {
            $metadata = $this->introspector->introspect($modelClass);
            $this->models[$modelClass] = $metadata;
            $this->tableIndex[$metadata->tableName] = $metadata;
        }
    }

    private function ensureScanned(): void
    {
        if (!$this->scanned) {
            $this->scan();
        }
    }

    private function directoryModifiedAfter(string $directory, int $timestamp): bool
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

    private function loadDirectoriesFromConfig(): array
    {
        $directories = [];

        try {
            $modelDirs = config('slice.eloquent.model_directories', ['app/Models']);

            foreach ($modelDirs as $dir) {
                $absPath = base_path($dir);
                $namespace = $this->dirToNamespace($dir);
                $directories[$absPath] = $namespace;
            }
        } catch (\Throwable) {
            // Fallback if config not available (e.g., in testing)
            $directories[base_path('app/Models')] = 'App\\Models';
        }

        return $directories;
    }

    private function dirToNamespace(string $dir): string
    {
        $parts = explode('/', trim($dir, '/'));
        return implode('\\', array_map('ucfirst', $parts));
    }
}
