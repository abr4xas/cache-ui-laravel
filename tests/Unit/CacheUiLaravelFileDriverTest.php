<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

describe('CacheUiLaravel File Driver Tests', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
    });

    describe('forgetKey with file driver', function (): void {
        it('successfully deletes a key from file cache by key content', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            $cachePath = storage_path('framework/cache/data');
            $testKey = 'test-cache-key';
            $mockFile = Mockery::mock();
            $mockFile->shouldReceive('getPathname')->andReturn($cachePath.'/test-file');

            // Mock File::exists for deleteFileKeyByKey (checking cache directory)
            File::shouldReceive('exists')->with($cachePath)->once()->andReturn(true);
            // Mock File::allFiles to return a file
            File::shouldReceive('allFiles')->with($cachePath)->once()->andReturn([$mockFile]);
            // Mock File::get to return wrapped data with the key
            $wrappedData = serialize(['key' => $testKey, 'value' => 'test-value']);
            $expiration = time() + 3600;
            File::shouldReceive('get')->with($cachePath.'/test-file')->once()->andReturn($expiration.$wrappedData);
            // Mock File::delete to return true
            File::shouldReceive('delete')->with($cachePath.'/test-file')->once()->andReturn(true);

            // Mock Cache facade for store validation
            $mockStore = Mockery::mock();
            $mockStore->shouldReceive('forget')->with($testKey)->andReturn(false);
            Cache::shouldReceive('store')->with('file')->andReturn($mockStore);

            $result = $this->cacheUiLaravel->forgetKey($testKey, 'file');
            expect($result)->toBeTrue();
        });

        it('successfully deletes a key from file cache by filename when key search fails', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            $cachePath = storage_path('framework/cache/data');
            $testKey = 'legacy-filename-key';
            $mockFile = Mockery::mock();
            $mockFile->shouldReceive('getPathname')->andReturn($cachePath.'/test-file');

            // First call: deleteFileKeyByKey - check directory exists
            File::shouldReceive('exists')->with($cachePath)->once()->andReturn(true);
            // Mock File::allFiles to return a file
            File::shouldReceive('allFiles')->with($cachePath)->once()->andReturn([$mockFile]);
            // Mock File::get to return data without matching key
            $wrappedData = serialize(['key' => 'other-key', 'value' => 'test-value']);
            $expiration = time() + 3600;
            File::shouldReceive('get')->with($cachePath.'/test-file')->once()->andReturn($expiration.$wrappedData);

            // Second call: deleteFileKeyByFilename - check file exists
            $filePath = $cachePath.'/'.$testKey;
            File::shouldReceive('exists')->with($filePath)->once()->andReturn(true);
            // Mock File::delete to return true
            File::shouldReceive('delete')->with($filePath)->once()->andReturn(true);

            // Mock Cache facade for store validation
            $mockStore = Mockery::mock();
            $mockStore->shouldReceive('forget')->with($testKey)->andReturn(false);
            Cache::shouldReceive('store')->with('file')->andReturn($mockStore);

            $result = $this->cacheUiLaravel->forgetKey($testKey, 'file');
            expect($result)->toBeTrue();
        });

        it('returns false when file cache directory does not exist', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            $cachePath = storage_path('framework/cache/data');
            $testKey = 'test-key';

            // Mock File::exists to return false (directory doesn't exist)
            File::shouldReceive('exists')->with($cachePath)->once()->andReturn(false);

            // Mock Cache facade for store validation
            $mockStore = Mockery::mock();
            $mockStore->shouldReceive('forget')->with($testKey)->andReturn(false);
            Cache::shouldReceive('store')->with('file')->andReturn($mockStore);

            $result = $this->cacheUiLaravel->forgetKey($testKey, 'file');
            expect($result)->toBeFalse();
        });

        it('returns false when key is not found in file cache', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', storage_path('framework/cache/data'));

            $cachePath = storage_path('framework/cache/data');
            $testKey = 'non-existent-key';
            $mockFile = Mockery::mock();
            $mockFile->shouldReceive('getPathname')->andReturn($cachePath.'/test-file');

            // First call: deleteFileKeyByKey - check directory exists
            File::shouldReceive('exists')->with($cachePath)->once()->andReturn(true);
            // Mock File::allFiles to return a file
            File::shouldReceive('allFiles')->with($cachePath)->once()->andReturn([$mockFile]);
            // Mock File::get to return data without matching key
            $wrappedData = serialize(['key' => 'other-key', 'value' => 'test-value']);
            $expiration = time() + 3600;
            File::shouldReceive('get')->with($cachePath.'/test-file')->once()->andReturn($expiration.$wrappedData);

            // Second call: deleteFileKeyByFilename - check file exists (should return false)
            $filePath = $cachePath.'/'.$testKey;
            File::shouldReceive('exists')->with($filePath)->once()->andReturn(false);

            // Mock Cache facade for store validation
            $mockStore = Mockery::mock();
            $mockStore->shouldReceive('forget')->with($testKey)->andReturn(false);
            Cache::shouldReceive('store')->with('file')->andReturn($mockStore);

            $result = $this->cacheUiLaravel->forgetKey($testKey, 'file');
            expect($result)->toBeFalse();
        });
    });

    describe('hashed file deletion', function (): void {
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

        it('does not attempt file deletion by filename for non-hashed keys', function (): void {
            Cache::shouldReceive('store')->withNoArgs()->andReturnSelf();
            Cache::shouldReceive('forget')->with('not-a-hash')->andReturn(false);

            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'file');
            $cachePath = storage_path('framework/cache/data');
            Config::set('cache.stores.file.path', $cachePath);

            // deleteFileKeyByKey will be called and check directory exists, but won't find the key
            File::shouldReceive('exists')->with($cachePath)->once()->andReturn(true);
            File::shouldReceive('allFiles')->with($cachePath)->once()->andReturn([]);
            // deleteFileKeyByFilename will be called but won't find the file (not a hash, direct path doesn't exist)
            $filePath = $cachePath.'/not-a-hash';
            File::shouldReceive('exists')->with($filePath)->once()->andReturn(false);

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
});

afterEach(function (): void {
    Mockery::close();
});
