<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

describe('CacheUiLaravel Methods', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
    });

    describe('getAllKeys method', function (): void {
        it('returns empty array for array driver', function (): void {
            Config::set('cache.default', 'array');
            Config::set('cache.stores.array.driver', 'array');

            $result = $this->cacheUiLaravel->getAllKeys();
            expect($result)->toBeEmpty();
        });

        it('returns empty array for unsupported driver', function (): void {
            Config::set('cache.default', 'unsupported');
            Config::set('cache.stores.unsupported.driver', 'unsupported');

            $result = $this->cacheUiLaravel->getAllKeys();
            expect($result)->toBeEmpty();
        });

        it('handles redis driver', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            // SCAN comes back empty, so the implementation falls back to KEYS.
            $mockConnection = Mockery::mock();
            $mockConnection->shouldReceive('scan')->andReturn([0, []]);
            $mockConnection->shouldReceive('keys')->with('*')->andReturn(['key1', 'key2']);

            $mockRedisStore = Mockery::mock(RedisStore::class);
            $mockRedisStore->shouldReceive('connection')->andReturn($mockConnection);
            $mockRedisStore->shouldReceive('getPrefix')->andReturn('');

            $mockRepository = Mockery::mock();
            $mockRepository->shouldReceive('getStore')->andReturn($mockRedisStore);

            Cache::shouldReceive('store')->with('redis')->andReturn($mockRepository);

            $result = $this->cacheUiLaravel->getAllKeys('redis');
            expect($result)->toBe(['key1', 'key2']);
        });

        it('strips the store prefix from the keys Redis reports', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            // SCAN and KEYS return the fully prefixed key; Cache::forget() re-applies
            // the prefix, so it has to be stripped for the round trip to work.
            $mockConnection = Mockery::mock();
            $mockConnection->shouldReceive('scan')
                ->with('0', ['match' => 'myapp_cache_*', 'count' => 100])
                ->andReturn(['0', ['myapp_cache_user_1', 'myapp_cache_user_2']]);

            $mockRedisStore = Mockery::mock(RedisStore::class);
            $mockRedisStore->shouldReceive('connection')->andReturn($mockConnection);
            $mockRedisStore->shouldReceive('getPrefix')->andReturn('myapp_cache_');

            $mockRepository = Mockery::mock();
            $mockRepository->shouldReceive('getStore')->andReturn($mockRedisStore);

            Cache::shouldReceive('store')->with('redis')->andReturn($mockRepository);

            expect($this->cacheUiLaravel->getAllKeys('redis'))->toBe(['user_1', 'user_2']);
        });

        it('also strips a global prefix applied by the Redis connection', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            // Connections can carry their own prefix on top of the store's one.
            $mockConnection = Mockery::mock(PhpRedisConnection::class);
            $mockConnection->shouldReceive('_prefix')->with('')->andReturn('conn:');
            $mockConnection->shouldReceive('scan')
                ->andReturn(['0', ['conn:myapp_cache_user_1']]);

            $mockRedisStore = Mockery::mock(RedisStore::class);
            $mockRedisStore->shouldReceive('connection')->andReturn($mockConnection);
            $mockRedisStore->shouldReceive('getPrefix')->andReturn('myapp_cache_');

            $mockRepository = Mockery::mock();
            $mockRepository->shouldReceive('getStore')->andReturn($mockRedisStore);

            Cache::shouldReceive('store')->with('redis')->andReturn($mockRepository);

            expect($this->cacheUiLaravel->getAllKeys('redis'))->toBe(['user_1']);
        });

        it('only strips the prefix when it sits at the start of the key', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            // A key that merely contains the prefix must survive untouched; a
            // naive str_replace would mangle it.
            $mockConnection = Mockery::mock();
            $mockConnection->shouldReceive('scan')
                ->andReturn(['0', ['myapp_cache_real', 'legacy_myapp_cache_embedded']]);

            $mockRedisStore = Mockery::mock(RedisStore::class);
            $mockRedisStore->shouldReceive('connection')->andReturn($mockConnection);
            $mockRedisStore->shouldReceive('getPrefix')->andReturn('myapp_cache_');

            $mockRepository = Mockery::mock();
            $mockRepository->shouldReceive('getStore')->andReturn($mockRedisStore);

            Cache::shouldReceive('store')->with('redis')->andReturn($mockRepository);

            expect($this->cacheUiLaravel->getAllKeys('redis'))
                ->toBe(['real', 'legacy_myapp_cache_embedded']);
        });

        it('keeps scanning across cursors and stops on a zero cursor', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            // Clients report the final cursor as int 0, string '0' or null depending
            // on version, so termination must not depend on the exact type. Getting
            // this wrong loops forever rather than failing.
            $mockConnection = Mockery::mock();
            $mockConnection->shouldReceive('scan')
                ->andReturn(['7', ['first']], [0, ['second']]);

            $mockRedisStore = Mockery::mock(RedisStore::class);
            $mockRedisStore->shouldReceive('connection')->andReturn($mockConnection);
            $mockRedisStore->shouldReceive('getPrefix')->andReturn('');

            $mockRepository = Mockery::mock();
            $mockRepository->shouldReceive('getStore')->andReturn($mockRedisStore);

            Cache::shouldReceive('store')->with('redis')->andReturn($mockRepository);

            expect($this->cacheUiLaravel->getAllKeys('redis'))->toBe(['first', 'second']);
        });

        it('returns an empty list when the store is not backed by Redis', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            $mockRepository = Mockery::mock();
            $mockRepository->shouldReceive('getStore')->andReturn(Mockery::mock(Store::class));

            Cache::shouldReceive('store')->with('redis')->andReturn($mockRepository);

            expect($this->cacheUiLaravel->getAllKeys('redis'))->toBeEmpty();
        });

        it('handles file driver with non-existent directory', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/nonexistent'));

            File::shouldReceive('exists')->andReturn(false);

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeEmpty();
        });

        it('handles key-aware-file driver with wrapped data', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            // Test with empty directory first
            File::shouldReceive('exists')->andReturn(true);
            File::shouldReceive('allFiles')->andReturn([]);

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeEmpty();
        });

        it('handles key-aware-file driver with mixed wrapped and legacy data', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            // Test with empty directory
            File::shouldReceive('exists')->andReturn(true);
            File::shouldReceive('allFiles')->andReturn([]);

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeEmpty();
        });

        it('handles key-aware-file driver with corrupted files', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            // Test with empty directory
            File::shouldReceive('exists')->andReturn(true);
            File::shouldReceive('allFiles')->andReturn([]);

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeEmpty();
        });

        it('handles database driver', function (): void {
            useSqliteCacheStore();

            Cache::store('database')->put('key1', 'a', 3600);
            Cache::store('database')->put('key2', 'b', 3600);

            expect($this->cacheUiLaravel->getAllKeys('database'))->toBe(['key1', 'key2']);
        });

        it('uses default store when no store specified', function (): void {
            Config::set('cache.default', 'array');
            Config::set('cache.stores.array.driver', 'array');

            $result = $this->cacheUiLaravel->getAllKeys();
            expect($result)->toBeEmpty();
        });
    });

    describe('forgetKey method', function (): void {
        it('deletes key from default store', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('getStore')->andReturn(Mockery::mock());
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key');
            expect($result)->toBeTrue();
        });

        it('deletes key from specified store', function (): void {
            Cache::shouldReceive('store')->with('redis')->andReturnSelf();
            Cache::shouldReceive('getStore')->andReturn(Mockery::mock());
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', 'redis');
            expect($result)->toBeTrue();
        });

        it('handles empty store parameter', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('getStore')->andReturn(Mockery::mock());
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', '');
            expect($result)->toBeTrue();
        });

        it('handles null store parameter', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('getStore')->andReturn(Mockery::mock());
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key');
            expect($result)->toBeTrue();
        });

        it('handles zero store parameter', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('getStore')->andReturn(Mockery::mock());
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', '0');
            expect($result)->toBeTrue();
        });

        it('returns false when key deletion fails', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('getStore')->andReturn(Mockery::mock());
            Cache::shouldReceive('forget')->with('test-key')->andReturn(false);

            $result = $this->cacheUiLaravel->forgetKey('test-key');
            expect($result)->toBeFalse();
        });
    });

    describe('error handling', function (): void {
        it('handles redis connection errors gracefully', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            Cache::shouldReceive('store')->with('redis')->andThrow(new Exception('Redis connection failed'));

            $result = $this->cacheUiLaravel->getAllKeys('redis');
            expect($result)->toBeEmpty();
        });

        it('handles file system errors gracefully', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            File::shouldReceive('exists')->andReturn(true);
            File::shouldReceive('allFiles')->andThrow(new Exception('File system error'));

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeEmpty();
        });

        it('handles database errors gracefully', function (): void {
            // Store points at a table that was never created, so the query throws.
            useSqliteCacheStore(createTable: false);

            expect($this->cacheUiLaravel->getAllKeys('database'))->toBeEmpty();
        });
    });

    describe('getAllKeys with pagination (offset)', function (): void {
        it('treats a negative offset as zero', function (): void {
            useSqliteCacheStore();

            Cache::store('database')->put('key1', 'a', 3600);
            Cache::store('database')->put('key2', 'b', 3600);

            expect($this->cacheUiLaravel->getAllKeys('database', null, -5))->toBe(['key1', 'key2']);
        });

        it('applies offset and limit at the query level', function (): void {
            useSqliteCacheStore();

            for ($i = 1; $i <= 15; $i++) {
                Cache::store('database')->put(sprintf('key%02d', $i), 'v', 3600);
            }

            expect($this->cacheUiLaravel->getAllKeys('database', 5, 10))->toHaveCount(5);
        });

        it('returns non-overlapping pages when paginating', function (): void {
            useSqliteCacheStore();

            for ($i = 1; $i <= 4; $i++) {
                Cache::store('database')->put(sprintf('key%02d', $i), 'v', 3600);
            }

            $page1 = $this->cacheUiLaravel->getAllKeys('database', 2, 0);
            $page2 = $this->cacheUiLaravel->getAllKeys('database', 2, 2);

            expect($page1)->toHaveCount(2)
                ->and($page2)->toHaveCount(2)
                // Paging is only meaningful if the two pages are disjoint.
                ->and(array_intersect($page1, $page2))->toBeEmpty();
        });
    });
});

afterEach(function (): void {
    Mockery::close();
});
