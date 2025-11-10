<?php

namespace NickPotts\Slice;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SliceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('slice')
            ->hasConfigFile()
            ->hasCommands([
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('slice.schema-provider-manager', function () {
            return new \NickPotts\Slice\Support\SchemaProviderManager;
        });

        $this->app->singleton(\NickPotts\Slice\Engine\Joins\JoinPathFinder::class, function ($app) {
            return new \NickPotts\Slice\Engine\Joins\JoinPathFinder(
                $app->make(\NickPotts\Slice\Support\CompiledSchema::class)
            );
        });

        $this->app->singleton(\NickPotts\Slice\Engine\Joins\JoinGraphBuilder::class, function ($app) {
            return new \NickPotts\Slice\Engine\Joins\JoinGraphBuilder(
                $app->make(\NickPotts\Slice\Engine\Joins\JoinPathFinder::class)
            );
        });

        $this->app->singleton(\NickPotts\Slice\Engine\Joins\JoinResolver::class, function ($app) {
            return new \NickPotts\Slice\Engine\Joins\JoinResolver(
                $app->make(\NickPotts\Slice\Engine\Joins\JoinGraphBuilder::class)
            );
        });

        $this->app->singleton(\NickPotts\Slice\Engine\QueryBuilder::class, function ($app) {
            return new \NickPotts\Slice\Engine\QueryBuilder(
                $app->make('slice.schema-provider-manager'),
                $app->make(\NickPotts\Slice\Engine\Joins\JoinResolver::class)
            );
        });

        $this->app->singleton('slice', function ($app) {
            return new \NickPotts\Slice\SliceManager($app->make('slice.schema-provider-manager'));
        });
    }

    public function packageBooted(): void
    {
        $this->registerAggregations();
        $this->registerEloquentProvider();
        $this->registerCompiledSchema();
        $this->registerFacadeAlias();
    }

    private function registerFacadeAlias(): void
    {
        $this->app->alias('slice', \NickPotts\Slice\Slice::class);
    }

    private function registerAggregations(): void
    {
        \NickPotts\Slice\Metrics\Aggregations\Sum::registerCompilers();
        \NickPotts\Slice\Metrics\Aggregations\Count::registerCompilers();
        \NickPotts\Slice\Metrics\Aggregations\Avg::registerCompilers();
    }

    private function registerEloquentProvider(): void
    {
        $manager = $this->app->make('slice.schema-provider-manager');
        $manager->register(new \NickPotts\Slice\Providers\Eloquent\EloquentSchemaProvider);
    }

    private function registerCompiledSchema(): void
    {
        $this->app->singleton('slice.compiled-schema', function ($app) {
            $manager = $app->make('slice.schema-provider-manager');

            return $manager->schema();
        });

        // Also register as the type-hinted class for direct injection
        $this->app->singleton(\NickPotts\Slice\Support\CompiledSchema::class, function ($app) {
            return $app->make('slice.compiled-schema');
        });
    }
}
