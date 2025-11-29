<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

describe('CacheUiLaravel File Driver Deletion', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
    });

    it('deletes hashed file when standard forget fails for file driver', function (): void {
        // Mock Cache::forget to return false, simulating that it couldn't find the key by name
        Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
        Cache::shouldReceive('forget')->with('008cb7ea48f292dd8b03d361a4c9f66085f77090')->andReturn(false);

        // Configure file driver
        Config::set('cache.default', 'file');
        Config::set('cache.stores.file.driver', 'file');
        $cachePath = storage_path('framework/cache/data');
        Config::set('cache.stores.file.path', $cachePath);

        // The key is a SHA1 hash
        $key = '008cb7ea48f292dd8b03d361a4c9f66085f77090';

        // Expected file path reconstruction
        // 00/8c/008cb7ea48f292dd8b03d361a4c9f66085f77090
        $expectedPath = $cachePath.'/00/8c/'.$key;

        // Mock File existence and deletion
        File::shouldReceive('exists')->with($expectedPath)->andReturn(true);
        File::shouldReceive('delete')->with($expectedPath)->andReturn(true);

        $result = $this->cacheUiLaravel->forgetKey($key);

        expect($result)->toBeTrue();
    });

    it('deletes hashed file when standard forget fails for key-aware-file driver', function (): void {
        // Mock Cache::forget to return false
        Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
        Cache::shouldReceive('forget')->with('008cb7ea48f292dd8b03d361a4c9f66085f77090')->andReturn(false);

        // Configure key-aware-file driver
        Config::set('cache.default', 'file');
        Config::set('cache.stores.file.driver', 'key-aware-file');
        $cachePath = storage_path('framework/cache/data');
        Config::set('cache.stores.file.path', $cachePath);

        $key = '008cb7ea48f292dd8b03d361a4c9f66085f77090';
        $expectedPath = $cachePath.'/00/8c/'.$key;

        File::shouldReceive('exists')->with($expectedPath)->andReturn(true);
        File::shouldReceive('delete')->with($expectedPath)->andReturn(true);

        $result = $this->cacheUiLaravel->forgetKey($key);

        expect($result)->toBeTrue();
    });

    it('does not attempt file deletion for non-hashed keys', function (): void {
        Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
        Cache::shouldReceive('forget')->with('not-a-hash')->andReturn(false);

        Config::set('cache.default', 'file');
        Config::set('cache.stores.file.driver', 'file');

        // File::exists/delete should NOT be called
        File::shouldReceive('exists')->never();
        File::shouldReceive('delete')->never();

        $result = $this->cacheUiLaravel->forgetKey('not-a-hash');

        expect($result)->toBeFalse();
    });

    it('does not attempt file deletion for non-file drivers', function (): void {
        Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
        Cache::shouldReceive('forget')->with('008cb7ea48f292dd8b03d361a4c9f66085f77090')->andReturn(false);

        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis.driver', 'redis');

        // File::exists/delete should NOT be called
        File::shouldReceive('exists')->never();
        File::shouldReceive('delete')->never();

        $result = $this->cacheUiLaravel->forgetKey('008cb7ea48f292dd8b03d361a4c9f66085f77090');

        expect($result)->toBeFalse();
    });
});
