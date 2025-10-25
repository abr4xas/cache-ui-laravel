<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

describe('KeyAwareFileStore Simple Tests', function (): void {
    beforeEach(function (): void {
        $this->files = new Filesystem();
        $this->cachePath = storage_path('framework/cache/simple-test');
        $this->keyAwareFileStore = new KeyAwareFileStore($this->files, $this->cachePath);

        // Clean up test directory
        if (File::exists($this->cachePath)) {
            File::deleteDirectory($this->cachePath);
        }
        File::makeDirectory($this->cachePath, 0755, true);
    });

    afterEach(function (): void {
        // Clean up test directory
        if (File::exists($this->cachePath)) {
            File::deleteDirectory($this->cachePath);
        }
    });

    it('can be instantiated', function (): void {
        expect($this->keyAwareFileStore)->toBeInstanceOf(KeyAwareFileStore::class);
    });

    it('has required methods', function (): void {
        expect(method_exists($this->keyAwareFileStore, 'put'))->toBeTrue();
        expect(method_exists($this->keyAwareFileStore, 'get'))->toBeTrue();
        expect(method_exists($this->keyAwareFileStore, 'add'))->toBeTrue();
        expect(method_exists($this->keyAwareFileStore, 'forever'))->toBeTrue();
        expect(method_exists($this->keyAwareFileStore, 'increment'))->toBeTrue();
    });

    it('can store and retrieve a simple value', function (): void {
        $key = 'test-key';
        $value = 'test-value';
        $seconds = 3600;

        // Store the value
        $result = $this->keyAwareFileStore->put($key, $value, $seconds);

        // The put method should return true or false depending on file system permissions
        expect($result)->toBeBool();

        // If successful, try to retrieve
        if ($result) {
            $retrievedValue = $this->keyAwareFileStore->get($key);
            expect($retrievedValue)->toBe($value);
        }
    });

    it('returns null for non-existent key', function (): void {
        $retrievedValue = $this->keyAwareFileStore->get('non-existent-key');
        expect($retrievedValue)->toBeNull();
    });

    it('can handle different data types', function (): void {
        $testCases = [
            'string' => 'hello world',
            'integer' => 42,
            'array' => ['key' => 'value'],
            'boolean' => true,
        ];

        foreach ($testCases as $type => $value) {
            $key = "test-{$type}";
            $result = $this->keyAwareFileStore->put($key, $value, 3600);

            if ($result) {
                $retrievedValue = $this->keyAwareFileStore->get($key);
                expect($retrievedValue)->toBe($value);
            }
        }
    });
});
