<?php

namespace NickPotts\Slice;

use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;
use NickPotts\Slice\Providers\Eloquent\EloquentSchemaProvider;
use NickPotts\Slice\Support\SchemaProviderManager;
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
        // Register SchemaProviderManager as singleton
        $this->app->singleton(SchemaProviderManager::class, function ($app) {
            $manager = new SchemaProviderManager;

            // Register EloquentSchemaProvider by default
            $namespaces = config('slice.eloquent.namespaces', ['App\\Models']);
            $manager->register(new EloquentSchemaProvider($namespaces));

            return $manager;
        });

        // Register QueryDriver as singleton
        $this->app->singleton(QueryDriver::class, function ($app) {
            return new LaravelQueryDriver;
        });
    }

    public function packageBooted(): void {}
}
