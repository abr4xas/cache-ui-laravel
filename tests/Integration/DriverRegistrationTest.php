<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * The service provider registers the "key-aware-file" driver by hand, so it has
 * to keep up with whatever Illuminate\Cache\CacheManager::createFileDriver()
 * passes to its own store. These tests pin the pieces that are easy to drop.
 */
describe('key-aware-file driver registration', function (): void {
    beforeEach(function (): void {
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/registration-'.getmypid();

        Config::set('cache.stores.kaf', [
            'driver' => 'key-aware-file',
            'path' => $this->cachePath,
        ]);
    });

    afterEach(function (): void {
        Cache::store('kaf')->flush();
    });

    it('resolves to a KeyAwareFileStore', function (): void {
        expect(Cache::store('kaf')->getStore())->toBeInstanceOf(KeyAwareFileStore::class);
    });

    it('forwards the configured serializable classes to the store', function (): void {
        // CacheManager reads this from the top-level cache config, not the store
        // config, and hands it to the store so unserialize() stays restricted.
        Config::set('cache.serializable_classes', false);

        Cache::purge('kaf');

        $store = Cache::store('kaf')->getStore();

        $reflection = new ReflectionProperty($store, 'serializableClasses');

        expect($reflection->getValue($store))->toBeFalse();
    });

    it('refuses to unserialize objects when serializable classes are disabled', function (): void {
        Config::set('cache.serializable_classes', false);
        Cache::purge('kaf');

        Cache::store('kaf')->put('an-object', new stdClass(), 60);

        // With allowed_classes => false, PHP hands back __PHP_Incomplete_Class
        // rather than reconstructing the object.
        expect(Cache::store('kaf')->get('an-object'))->not->toBeInstanceOf(stdClass::class);
    });

    it('reconstructs objects when serializable classes are unrestricted', function (): void {
        Config::set(['cache.serializable_classes' => null]);
        Cache::purge('kaf');

        Cache::store('kaf')->put('an-object', new stdClass(), 60);

        expect(Cache::store('kaf')->get('an-object'))->toBeInstanceOf(stdClass::class);
    });

    it('passes the store config through to the repository', function (): void {
        $repository = Cache::store('kaf');

        $reflection = new ReflectionProperty($repository, 'config');

        // Repository keeps Arr::only($config, ['store']); an empty array here
        // means cache events would be dispatched without a store name.
        expect($reflection->getValue($repository))->toHaveKey('store');
    });
});
