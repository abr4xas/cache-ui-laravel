<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class CacheUiLaravelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

        $this->publishes([
            __DIR__.'/../config/cache-ui-laravel.php' => config_path('cache-ui-laravel.php'),
        ], 'cache-ui-laravel-config');

        $this->commands([
            CacheUiLaravelCommand::class,
        ]);
    }

    public function register(): void
    {
        $this->app->singleton(CacheUiLaravel::class, fn (): CacheUiLaravel => new CacheUiLaravel);

        // Register the "key-aware-file" cache driver so users only need to set
        // 'driver' => 'key-aware-file' in config/cache.php. The driver is registered
        // within a "booting" callback so it is available before any service provider's
        // boot method attempts to read from the cache.
        $this->app->booting(function (): void {
            Cache::extend('key-aware-file', fn (Application $app, array $config): Repository => Cache::repository(
                new KeyAwareFileStore(
                    $app->make(Filesystem::class),
                    $config['path'] ?? storage_path('framework/cache/data'),
                    $config['permission'] ?? $config['file_permission'] ?? null,
                )->setLockDirectory($config['lock_path'] ?? null)
            ));
        });
    }
}
