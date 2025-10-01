<?php

declare(strict_types=1);

namespace Tests;

use Abr4xas\CacheUiLaravel\CacheUiLaravelServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{

    protected function getPackageProviders($app): array
    {
        return [
            CacheUiLaravelServiceProvider::class,
        ];
    }
}
