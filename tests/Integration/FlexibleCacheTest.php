<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

describe('Cache::flexible() handling', function (): void {
    beforeEach(function (): void {
        $this->cacheUiLaravel = new CacheUiLaravel();
        $this->files = new Filesystem();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/flexible-test';

        if ($this->files->exists($this->cachePath)) {
            $this->files->deleteDirectory($this->cachePath);
        }
        $this->files->makeDirectory($this->cachePath, 0755, true);

        Config::set('cache.default', 'file');
        Config::set('cache.stores.file.driver', 'key-aware-file');
        Config::set('cache.stores.file.path', $this->cachePath);
    });

    afterEach(function (): void {
        if ($this->files->exists($this->cachePath)) {
            $this->files->deleteDirectory($this->cachePath);
        }
    });

    it('hides the internal flexible companion key from the listing by default', function (): void {
        Cache::store('file')->flexible('users', [5, 10], fn (): string => 'payload');

        $keys = $this->cacheUiLaravel->getAllKeys('file');

        expect($keys)->toContain('users')
            ->and($keys)->not->toContain('illuminate:cache:flexible:created:users');
    });

    it('exposes the internal companion key when hide_internal_keys is disabled', function (): void {
        Config::set('cache-ui-laravel.hide_internal_keys', false);

        Cache::store('file')->flexible('reports', [5, 10], fn (): string => 'payload');

        $keys = $this->cacheUiLaravel->getAllKeys('file');

        expect($keys)->toContain('reports')
            ->and($keys)->toContain('illuminate:cache:flexible:created:reports');
    });

    it('drops the deleted flexible key from the listing', function (): void {
        Cache::store('file')->flexible('articles', [5, 10], fn (): string => 'payload');

        $this->cacheUiLaravel->forgetKey('articles', 'file');

        // The value is gone and the default (filtered) listing stays clean: the
        // user key is deleted and its internal companion is never shown anyway.
        expect(Cache::store('file')->get('articles'))->toBeNull()
            ->and($this->cacheUiLaravel->getAllKeys('file'))->not->toContain('articles');
    });
});
