<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;
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
    }
}
