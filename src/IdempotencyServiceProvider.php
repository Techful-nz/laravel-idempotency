<?php

namespace Techful\Idempotency;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Techful\Idempotency\Http\Middleware\Idempotent;
use Techful\Idempotency\View\Components\IdempotencyKey;

class IdempotencyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-idempotency')
            ->hasConfigFile()
            ->hasViews()
            ->hasAssets();
    }

    public function packageBooted(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('idempotent', Idempotent::class);

        Blade::component('idempotency-key', IdempotencyKey::class);
    }
}
