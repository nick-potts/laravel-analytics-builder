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
            return new \NickPotts\Slice\Support\SchemaProviderManager();
        });

        $this->app->singleton('slice', function ($app) {
            return new \NickPotts\Slice\SliceManager($app->make('slice.schema-provider-manager'));
        });
    }

    public function packageBooted(): void
    {
        $this->registerAggregations();
        $this->registerEloquentProvider();
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
        $manager->register(new \NickPotts\Slice\Providers\Eloquent\EloquentSchemaProvider());
    }
}
