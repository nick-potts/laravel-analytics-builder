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

    public function packageRegistered(): void {}

    public function packageBooted(): void {}
}
