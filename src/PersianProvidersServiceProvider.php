<?php

namespace Larevio\PersianProviders;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PersianProvidersServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('persianproviders')
            ->hasConfigFile()
            ->hasViews()
            ->hasAssets();
    }
}
