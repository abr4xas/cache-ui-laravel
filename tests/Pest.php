<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->in(__DIR__);

/**
 * Point the "database" cache store at an in-memory SQLite database.
 *
 * Exercising the database driver against a real connection keeps these tests
 * honest about the SQL that is actually built -- table name, connection and
 * key prefix all come off the resolved store rather than from mocked facades.
 *
 * @param  bool  $createTable  Whether to create the cache table; pass false to
 *                             simulate a misconfigured store whose query fails.
 */
function useSqliteCacheStore(bool $createTable = true): void
{
    Illuminate\Support\Facades\Config::set('database.connections.cacheui', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Illuminate\Support\Facades\Config::set('cache.stores.database', [
        'driver' => 'database',
        'table' => 'cache',
        'connection' => 'cacheui',
    ]);

    Illuminate\Support\Facades\Cache::purge('database');

    if (! $createTable) {
        return;
    }

    Illuminate\Support\Facades\Schema::connection('cacheui')->create('cache', function ($table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
}
