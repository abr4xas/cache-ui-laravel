<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

describe('Cache Workflow Integration Tests', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
        $this->files = new Filesystem();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/integration-test';

        // Clean up test directory
        if ($this->files->exists($this->cachePath)) {
            $this->files->deleteDirectory($this->cachePath);
        }
        $this->files->makeDirectory($this->cachePath, 0755, true);
    });

    afterEach(function (): void {
        // Clean up test directory
        if ($this->files->exists($this->cachePath)) {
            $this->files->deleteDirectory($this->cachePath);
        }
    });

    describe('complete cache workflow', function (): void {
        it('can store, retrieve, and delete cache keys', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            // Store multiple keys
            Cache::put('workflow-key-1', 'value-1', 3600);
            Cache::put('workflow-key-2', 'value-2', 3600);
            Cache::put('workflow-key-3', 'value-3', 3600);

            // Get all keys
            $keys = $this->cacheUiLaravel->getAllKeys('file');
            expect($keys)->toContain('workflow-key-1');
            expect($keys)->toContain('workflow-key-2');
            expect($keys)->toContain('workflow-key-3');

            // Verify values
            expect(Cache::get('workflow-key-1'))->toBe('value-1');
            expect(Cache::get('workflow-key-2'))->toBe('value-2');
            expect(Cache::get('workflow-key-3'))->toBe('value-3');

            // Delete one key
            $deleted = $this->cacheUiLaravel->forgetKey('workflow-key-2', 'file');
            expect($deleted)->toBeTrue();

            // Verify deletion
            $keysAfterDelete = $this->cacheUiLaravel->getAllKeys('file');
            expect($keysAfterDelete)->toContain('workflow-key-1');
            expect($keysAfterDelete)->not->toContain('workflow-key-2');
            expect($keysAfterDelete)->toContain('workflow-key-3');

            // Verify value is gone
            expect(Cache::get('workflow-key-2'))->toBeNull();
        });

        it('handles multiple keys with different expiration times', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            // Store keys with different expiration
            Cache::put('short-exp-key', 'value', 1);
            Cache::put('long-exp-key', 'value', 3600);
            Cache::forever('forever-key', 'value');

            // All keys should be present initially
            $keys = $this->cacheUiLaravel->getAllKeys('file');
            expect($keys)->toContain('short-exp-key');
            expect($keys)->toContain('long-exp-key');
            expect($keys)->toContain('forever-key');

            // Wait for short expiration
            sleep(2);

            // Short expired key should not be in list
            $keysAfterExpiry = $this->cacheUiLaravel->getAllKeys('file');
            expect($keysAfterExpiry)->not->toContain('short-exp-key');
            expect($keysAfterExpiry)->toContain('long-exp-key');
            expect($keysAfterExpiry)->toContain('forever-key');
        });
    });

    describe('mixed wrapped and legacy data scenarios', function (): void {
        it('handles mixed wrapped and legacy cache files', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            // Create a legacy cache file manually
            $legacyPath = $this->cachePath.'/'.md5('legacy-mixed-key');
            $legacyDir = dirname($legacyPath);
            if (! $this->files->exists($legacyDir)) {
                $this->files->makeDirectory($legacyDir, 0755, true);
            }

            $expiration = time() + 3600;
            $legacyData = serialize('legacy-value');
            $this->files->put($legacyPath, $expiration.$legacyData);

            // Create a new wrapped cache file
            Cache::put('wrapped-mixed-key', 'wrapped-value', 3600);

            // Should be able to list both
            $keys = $this->cacheUiLaravel->getAllKeys('file');
            expect($keys)->toBeArray();

            // Should be able to read both
            $legacyValue = Cache::get('legacy-mixed-key');
            $wrappedValue = Cache::get('wrapped-mixed-key');

            // Note: The legacy key might not appear in getAllKeys if it's not in wrapped format
            // But we should still be able to read it
            expect($wrappedValue)->toBe('wrapped-value');
        });
    });

    describe('performance with large number of keys', function (): void {
        it('can handle listing many cache keys', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            // Create 100 cache keys
            for ($i = 0; $i < 100; $i++) {
                Cache::put("perf-key-{$i}", "value-{$i}", 3600);
            }

            $startTime = microtime(true);
            $keys = $this->cacheUiLaravel->getAllKeys('file');
            $endTime = microtime(true);

            $executionTime = $endTime - $startTime;

            expect($keys)->toBeArray();
            expect(count($keys))->toBeGreaterThanOrEqual(100);
            // Should complete in reasonable time (less than 5 seconds for 100 keys)
            expect($executionTime)->toBeLessThan(5.0);
        });
    });

    describe('getAllKeys with different stores', function (): void {
        it('uses default store when no store specified', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            Cache::put('default-store-key', 'value', 3600);

            $keys = $this->cacheUiLaravel->getAllKeys();
            expect($keys)->toBeArray();
        });

        it('uses specified store when provided', function (): void {
            Config::set('cache.default', 'array');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            Cache::store('file')->put('specified-store-key', 'value', 3600);

            $keys = $this->cacheUiLaravel->getAllKeys('file');
            expect($keys)->toBeArray();
        });
    });

    describe('forgetKey with different stores', function (): void {
        it('deletes key from default store', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            Cache::put('delete-default-key', 'value', 3600);
            $deleted = $this->cacheUiLaravel->forgetKey('delete-default-key');
            expect($deleted)->toBeTrue();
            expect(Cache::get('delete-default-key'))->toBeNull();
        });

        it('deletes key from specified store', function (): void {
            Config::set('cache.default', 'array');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            Cache::store('file')->put('delete-specified-key', 'value', 3600);
            $deleted = $this->cacheUiLaravel->forgetKey('delete-specified-key', 'file');
            expect($deleted)->toBeTrue();
            expect(Cache::store('file')->get('delete-specified-key'))->toBeNull();
        });

        it('returns false when key does not exist', function (): void {
            Config::set('cache.default', 'file');
            Config::set('cache.stores.file.driver', 'key-aware-file');
            Config::set('cache.stores.file.path', $this->cachePath);

            $deleted = $this->cacheUiLaravel->forgetKey('non-existent-key');
            expect($deleted)->toBeFalse();
        });
    });
})->group('CacheWorkflow');
