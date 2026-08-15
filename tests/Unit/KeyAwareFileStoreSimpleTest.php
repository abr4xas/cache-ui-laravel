<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Filesystem\Filesystem;

describe('KeyAwareFileStore Simple Tests', function (): void {
    beforeEach(function (): void {
        $this->files = new Filesystem();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/simple-test';
        $this->keyAwareFileStore = new KeyAwareFileStore($this->files, $this->cachePath);

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

    it('can store and retrieve a simple value', function (): void {
        $key = 'test-key';
        $value = 'test-value';
        $seconds = 3600;

        expect($this->keyAwareFileStore->put($key, $value, $seconds))->toBeTrue()
            ->and($this->keyAwareFileStore->get($key))->toBe($value);
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
