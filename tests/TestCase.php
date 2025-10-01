<?php

declare(strict_types=1);

namespace Tests;

use Abr4xas\CacheUiLaravel\CacheUiLaravelServiceProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Orchestra\Testbench\Concerns\CreatesApplication;

class TestCase extends \Orchestra\Testbench\TestCase
{

    protected function getPackageProviders($app): array
    {
        return [
            CacheUiLaravelServiceProvider::class,
        ];
    }
}
