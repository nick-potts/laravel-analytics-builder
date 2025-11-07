<?php

namespace NickPotts\Slice;

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\MySqlConnection;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SliceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('slice')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_slice_tables')
            ->hasCommands([
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerDatabaseExtensions();

        $this->app->singleton(QueryDriver::class, fn () => new LaravelQueryDriver(config('database.default')));
        $this->app->singleton(Slice::class, static fn () => new Slice);
        $this->app->singleton(\NickPotts\Slice\Support\Registry::class);
    }

    public function packageBooted(): void
    {
        // Auto-discover and register metric enums
        $this->registerMetricEnums();
    }

    protected function registerDatabaseExtensions(): void
    {
        $this->app['db']->extend('singlestore', function (array $config, string $name) {
            $config['name'] = $name;

            $connector = new MySqlConnector();
            $pdo = $connector->connect($config);

            return new MySqlConnection($pdo, $config['database'] ?? null, $config['prefix'] ?? '', $config);
        });
    }

    protected function registerMetricEnums(): void
    {
        $registry = $this->app->make(\NickPotts\Slice\Support\Registry::class);

        // Get metric enum classes from config or auto-discover
        $metricEnums = config('slice.metric_enums', []);

        // If no config, try to auto-discover from app/Analytics
        if (empty($metricEnums)) {
            $metricEnums = $this->discoverMetricEnums();
        }

        foreach ($metricEnums as $enumClass) {
            if (enum_exists($enumClass) && is_subclass_of($enumClass, \NickPotts\Slice\Contracts\MetricContract::class)) {
                $registry->registerMetricEnum($enumClass);
            }
        }
    }

    protected function discoverMetricEnums(): array
    {
        $enums = [];
        $analyticsPath = app_path('Analytics');

        if (! is_dir($analyticsPath)) {
            return $enums;
        }

        // Recursively find all *Metric.php files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($analyticsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_ends_with($file->getFilename(), 'Metric.php')) {
                // Extract namespace and class name from file
                $relativePath = str_replace($analyticsPath.'/', '', $file->getPathname());
                $relativePath = str_replace('.php', '', $relativePath);
                $relativePath = str_replace('/', '\\', $relativePath);

                $className = 'App\\Analytics\\'.$relativePath;

                if (enum_exists($className)) {
                    $enums[] = $className;
                }
            }
        }

        return $enums;
    }
}
