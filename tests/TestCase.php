<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Abr4xas\CacheUiLaravel\CacheUiLaravelServiceProvider;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            // The "key-aware-file" cache driver is registered automatically by the
            // service provider, so no manual Cache::extend() call is needed here.
            CacheUiLaravelServiceProvider::class,
        ];
    }
}
