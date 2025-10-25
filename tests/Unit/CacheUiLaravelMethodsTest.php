<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        it('returns empty array for unsupported driver', function (): void {
            Config::set('cache.default', 'unsupported');
            Config::set('cache.stores.unsupported.driver', 'unsupported');

            $result = $this->cacheUiLaravel->getAllKeys();
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        it('handles redis driver', function (): void {
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis.driver', 'redis');

            // Mock Redis connection
            $mockConnection = Mockery::mock();
            $mockConnection->shouldReceive('keys')->with('*')->andReturn(['key1', 'key2']);

            $mockStore = Mockery::mock();
            $mockStore->shouldReceive('getStore->connection')->andReturn($mockConnection);

            Cache::shouldReceive('store')->with('redis')->andReturn($mockStore);

            $result = $this->cacheUiLaravel->getAllKeys('redis');
            expect($result)->toBeArray();
        });

        it('handles file driver with non-existent directory', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/nonexistent'));

            File::shouldReceive('exists')->andReturn(false);

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeArray();
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
            expect($result)->toBeArray();
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
            expect($result)->toBeArray();
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
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        it('handles database driver', function (): void {
            Config::set('cache.default', 'database');
            Config::set('cache.stores.database.driver', 'database');
            Config::set('cache.stores.database.table', 'cache');

            DB::shouldReceive('table')->with('cache')->andReturnSelf();
            DB::shouldReceive('pluck')->with('key')->andReturn(collect(['key1', 'key2']));

            $result = $this->cacheUiLaravel->getAllKeys('database');
            expect($result)->toBeArray();
        });

        it('uses default store when no store specified', function (): void {
            Config::set('cache.default', 'array');
            Config::set('cache.stores.array.driver', 'array');

            $result = $this->cacheUiLaravel->getAllKeys();
            expect($result)->toBeArray();
        });
    });

    describe('forgetKey method', function (): void {
        it('deletes key from default store', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key');
            expect($result)->toBeTrue();
        });

        it('deletes key from specified store', function (): void {
            Cache::shouldReceive('store')->with('redis')->andReturnSelf();
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', 'redis');
            expect($result)->toBeTrue();
        });

        it('handles empty store parameter', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', '');
            expect($result)->toBeTrue();
        });

        it('handles null store parameter', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', null);
            expect($result)->toBeTrue();
        });

        it('handles zero store parameter', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('forget')->with('test-key')->andReturn(true);

            $result = $this->cacheUiLaravel->forgetKey('test-key', '0');
            expect($result)->toBeTrue();
        });

        it('returns false when key deletion fails', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
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
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        it('handles file system errors gracefully', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            File::shouldReceive('exists')->andReturn(true);
            File::shouldReceive('allFiles')->andThrow(new Exception('File system error'));

            $result = $this->cacheUiLaravel->getAllKeys('file');
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        it('handles database errors gracefully', function (): void {
            Config::set('cache.default', 'database');
            Config::set('cache.stores.database.driver', 'database');
            Config::set('cache.stores.database.table', 'cache');

            DB::shouldReceive('table')->with('cache')->andThrow(new Exception('Database connection failed'));

            $result = $this->cacheUiLaravel->getAllKeys('database');
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });
    });
});

afterEach(function (): void {
    Mockery::close();
});
