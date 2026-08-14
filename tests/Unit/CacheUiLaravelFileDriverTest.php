<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * forgetKey() falls back to scanning the cache directory when Cache::forget()
 * cannot find the key by its hashed path. These tests drive that fallback with
 * real files on disk rather than mocked File facade calls, so they assert what
 * actually happened to the filesystem instead of which methods were called.
 */
describe('CacheUiLaravel File Driver Tests', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/file-driver-'.getmypid();

        File::deleteDirectory($this->cachePath);
        File::makeDirectory($this->cachePath, 0755, true);

        Config::set('cache.stores.filetest', [
            'driver' => 'key-aware-file',
            'path' => $this->cachePath,
        ]);
        Cache::purge('filetest');
    });

    afterEach(function (): void {
        File::deleteDirectory($this->cachePath);
    });

    /**
     * Write a cache file by hand at a path that deliberately does not match
     * sha1($key), so Cache::forget() misses it and the fallback has to run.
     */
    $writeCacheFile = function (string $path, string $key, mixed $value = 'test-value'): void {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (time() + 3600).serialize(['key' => $key, 'value' => $value]));
    };

    describe('forgetKey with file driver', function () use ($writeCacheFile): void {
        it('deletes a cache file by the key recorded in its contents', function () use ($writeCacheFile): void {
            $orphan = $this->cachePath.'/zz/zz/orphaned-file';
            $writeCacheFile($orphan, 'test-cache-key');

            expect($this->cacheUiLaravel->forgetKey('test-cache-key', 'filetest'))->toBeTrue()
                ->and(File::exists($orphan))->toBeFalse();
        });

        it('deletes by filename when no file records a matching key', function () use ($writeCacheFile): void {
            $decoy = $this->cachePath.'/zz/zz/decoy';
            $writeCacheFile($decoy, 'some-other-key');

            $legacy = $this->cachePath.'/legacy-filename-key';
            $writeCacheFile($legacy, 'some-other-key');

            expect($this->cacheUiLaravel->forgetKey('legacy-filename-key', 'filetest'))->toBeTrue()
                ->and(File::exists($legacy))->toBeFalse()
                // The decoy records a different key, so it must survive.
                ->and(File::exists($decoy))->toBeTrue();
        });

        it('returns false when the cache directory does not exist', function (): void {
            Config::set('cache.stores.filetest.path', $this->cachePath.'/nonexistent');
            Cache::purge('filetest');

            expect($this->cacheUiLaravel->forgetKey('test-key', 'filetest'))->toBeFalse();
        });

        it('returns false when the key is nowhere in the cache directory', function () use ($writeCacheFile): void {
            $decoy = $this->cachePath.'/zz/zz/decoy';
            $writeCacheFile($decoy, 'some-other-key');

            expect($this->cacheUiLaravel->forgetKey('non-existent-key', 'filetest'))->toBeFalse()
                ->and(File::exists($decoy))->toBeTrue();
        });
    });

    describe('hashed file deletion', function (): void {
        // Laravel nests hashed keys as <first two>/<next two>/<full hash>.
        $hash = '008cb7ea48f292dd8b03d361a4c9f66085f77090';

        it('deletes a hashed file whose name is passed as the key', function () use ($hash): void {
            $hashedPath = $this->cachePath.'/00/8c/'.$hash;
            File::ensureDirectoryExists(dirname($hashedPath));
            File::put($hashedPath, (time() + 3600).serialize('legacy-unwrapped-value'));

            expect($this->cacheUiLaravel->forgetKey($hash, 'filetest'))->toBeTrue()
                ->and(File::exists($hashedPath))->toBeFalse();
        });

        it('behaves the same for the plain file driver', function () use ($hash): void {
            Config::set('cache.stores.filetest.driver', 'file');
            Cache::purge('filetest');

            $hashedPath = $this->cachePath.'/00/8c/'.$hash;
            File::ensureDirectoryExists(dirname($hashedPath));
            File::put($hashedPath, (time() + 3600).serialize('legacy-unwrapped-value'));

            expect($this->cacheUiLaravel->forgetKey($hash, 'filetest'))->toBeTrue()
                ->and(File::exists($hashedPath))->toBeFalse();
        });

        it('does not reconstruct a hashed path for a non-hashed key', function (): void {
            expect($this->cacheUiLaravel->forgetKey('not-a-hash', 'filetest'))->toBeFalse();
        });

        it('leaves cache files alone for stores that are not file backed', function () use ($hash): void {
            Config::set('cache.stores.arraytest', ['driver' => 'array']);

            // A file sitting at the hashed path the fallback would have targeted.
            $hashedPath = $this->cachePath.'/00/8c/'.$hash;
            File::ensureDirectoryExists(dirname($hashedPath));
            File::put($hashedPath, (time() + 3600).serialize(['key' => $hash, 'value' => 'v']));

            expect($this->cacheUiLaravel->forgetKey($hash, 'arraytest'))->toBeFalse()
                // An array store must never trigger the filesystem fallback.
                ->and(File::exists($hashedPath))->toBeTrue();
        });
    });
});
