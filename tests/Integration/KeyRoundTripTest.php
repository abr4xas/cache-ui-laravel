<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The invariant that holds the package together: every key getAllKeys() reports
 * must be deletable by forgetKey().
 *
 * This is what silently broke. getAllKeys() returned storage-level keys (with the
 * cache prefix still attached) while forgetKey() hands its argument to
 * Cache::forget(), which re-applies that same prefix. The round trip therefore
 * looked for a double-prefixed key, deleted nothing, and still reported success.
 */
describe('getAllKeys and forgetKey round trip', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/roundtrip-'.getmypid();
        File::deleteDirectory($this->cachePath);

        // A non-empty prefix is the Laravel default, and it is what exposed the bug.
        Config::set('cache.prefix', 'myapp_cache_');
    });

    afterEach(function (): void {
        File::deleteDirectory($this->cachePath);
    });

    describe('database store', function (): void {
        beforeEach(function (): void {
            Config::set('database.connections.cacheui', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            Config::set('cache.stores.dbtest', [
                'driver' => 'database',
                'table' => 'cache',
                'connection' => 'cacheui',
            ]);
            Cache::purge('dbtest');

            Schema::connection('cacheui')->create('cache', function ($table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        });

        it('lists the key the application used, not the prefixed storage key', function (): void {
            Cache::store('dbtest')->put('user_1_profile', 'data', 3600);

            expect($this->cacheUiLaravel->getAllKeys('dbtest'))->toBe(['user_1_profile']);
        });

        it('actually removes the row when deleting a listed key', function (): void {
            Cache::store('dbtest')->put('user_1_profile', 'data', 3600);

            $listed = $this->cacheUiLaravel->getAllKeys('dbtest');

            expect($this->cacheUiLaravel->forgetKey($listed[0], 'dbtest'))->toBeTrue()
                ->and(Cache::store('dbtest')->get('user_1_profile'))->toBeNull()
                ->and($this->cacheUiLaravel->getAllKeys('dbtest'))->toBeEmpty();
        });

        it('reads the table through the store configured connection', function (): void {
            // The default connection has no cache table at all, so a listing that
            // reached it instead of "cacheui" would error out and return [].
            Cache::store('dbtest')->put('connection-scoped', 'data', 3600);

            expect($this->cacheUiLaravel->getAllKeys('dbtest'))->toBe(['connection-scoped']);
        });
    });

    describe('file store', function (): void {
        beforeEach(function (): void {
            Config::set('cache.stores.customfile', [
                'driver' => 'key-aware-file',
                'path' => $this->cachePath,
            ]);
            Cache::purge('customfile');
        });

        it('lists keys from a store that is not named "file"', function (): void {
            // getFileKeys() used to read cache.stores.file.path regardless of which
            // store was asked for, so any other store name returned nothing.
            Config::set('cache.stores.file.path', $this->cachePath.'/somewhere-else');

            Cache::store('customfile')->put('custom-store-key', 'data', 3600);

            expect($this->cacheUiLaravel->getAllKeys('customfile'))->toBe(['custom-store-key']);
        });

        it('round trips a key from listing to deletion', function (): void {
            Cache::store('customfile')->put('round-trip-key', 'data', 3600);

            $listed = $this->cacheUiLaravel->getAllKeys('customfile');

            expect($listed)->toBe(['round-trip-key'])
                ->and($this->cacheUiLaravel->forgetKey($listed[0], 'customfile'))->toBeTrue()
                ->and($this->cacheUiLaravel->getAllKeys('customfile'))->toBeEmpty();
        });

        it('is unaffected by the cache prefix, which file stores never apply', function (): void {
            Cache::store('customfile')->put('unprefixed', 'data', 3600);

            // FileStore::getPrefix() returns '', so nothing should be stripped.
            expect($this->cacheUiLaravel->getAllKeys('customfile'))->toBe(['unprefixed']);
        });
    });
});
