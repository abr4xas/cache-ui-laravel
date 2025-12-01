<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Application;
use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Abr4xas\CacheUiLaravel\CacheUiLaravelServiceProvider;

class TestCase extends BaseTestCase
{

    protected function getPackageProviders($app): array
    {
        return [
            CacheUiLaravelServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Register the key-aware-file driver for testing
        Cache::extend('key-aware-file', fn(Application $app, array $config) => Cache::repository(new KeyAwareFileStore(
			$app->make(Filesystem::class),
            $config['path'] ?? storage_path('framework/cache/data'),
            $config['file_permission'] ?? null
        )));
    }
}
