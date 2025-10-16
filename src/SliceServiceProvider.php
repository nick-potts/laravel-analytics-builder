<?php

namespace NickPotts\Slice;

use NickPotts\Slice\Commands\SliceCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SliceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('slice')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_slice_table')
            ->hasCommand(SliceCommand::class);
    }
}
