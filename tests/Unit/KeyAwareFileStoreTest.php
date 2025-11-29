<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Filesystem\Filesystem;

describe('KeyAwareFileStore Complete Tests', function (): void {
    beforeEach(function (): void {
        $this->files = new Filesystem();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/key-aware-test';
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

    describe('put method', function (): void {
        it('stores string values correctly', function (): void {
            $result = $this->keyAwareFileStore->put('string-key', 'test-value', 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('string-key');
            expect($value)->toBe('test-value');
        });

        it('stores integer values correctly', function (): void {
            $result = $this->keyAwareFileStore->put('int-key', 42, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('int-key');
            expect($value)->toBe(42);
        });

        it('stores array values correctly', function (): void {
            $array = ['key1' => 'value1', 'key2' => 'value2'];
            $result = $this->keyAwareFileStore->put('array-key', $array, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('array-key');
            expect($value)->toBe($array);
        });

        it('stores boolean values correctly', function (): void {
            $result = $this->keyAwareFileStore->put('bool-key', true, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('bool-key');
            expect($value)->toBeTrue();
        });

        it('stores null values correctly', function (): void {
            $result = $this->keyAwareFileStore->put('null-key', null, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('null-key');
            expect($value)->toBeNull();
        });

        it('stores object values correctly', function (): void {
            $object = (object) ['property' => 'value'];
            $result = $this->keyAwareFileStore->put('object-key', $object, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('object-key');
            expect($value)->toBeInstanceOf(stdClass::class);
            expect($value->property)->toBe('value');
        });

        it('handles zero expiration time', function (): void {
            $result = $this->keyAwareFileStore->put('zero-exp-key', 'value', 0);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('zero-exp-key');
            expect($value)->toBe('value');
        });
    });

    describe('get method', function (): void {
        it('retrieves wrapped data correctly', function (): void {
            $this->keyAwareFileStore->put('wrapped-key', 'wrapped-value', 3600);
            $value = $this->keyAwareFileStore->get('wrapped-key');
            expect($value)->toBe('wrapped-value');
        });

        it('returns null for expired keys', function (): void {
            // Put with very short expiration
            $this->keyAwareFileStore->put('expired-key', 'value', 1);
            sleep(2);
            $value = $this->keyAwareFileStore->get('expired-key');
            expect($value)->toBeNull();
        });

        it('returns null for non-existent keys', function (): void {
            $value = $this->keyAwareFileStore->get('non-existent');
            expect($value)->toBeNull();
        });
    });

    describe('add method', function (): void {
        it('adds a new key successfully', function (): void {
            $result = $this->keyAwareFileStore->add('new-key', 'new-value', 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('new-key');
            expect($value)->toBe('new-value');
        });

        it('returns false when key already exists', function (): void {
            $this->keyAwareFileStore->put('existing-key', 'value', 3600);
            $result = $this->keyAwareFileStore->add('existing-key', 'new-value', 3600);
            expect($result)->toBeFalse();

            // Original value should remain
            $value = $this->keyAwareFileStore->get('existing-key');
            expect($value)->toBe('value');
        });

        it('adds key when previous key is expired', function (): void {
            $this->keyAwareFileStore->put('expired-key', 'old-value', 1);
            sleep(2);
            $result = $this->keyAwareFileStore->add('expired-key', 'new-value', 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('expired-key');
            expect($value)->toBe('new-value');
        });
    });

    describe('forever method', function (): void {
        it('stores values indefinitely', function (): void {
            $result = $this->keyAwareFileStore->forever('forever-key', 'forever-value');
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('forever-key');
            expect($value)->toBe('forever-value');
        });
    });

    describe('increment method', function (): void {
        it('increments numeric values', function (): void {
            $this->keyAwareFileStore->put('counter', 10, 3600);
            $result = $this->keyAwareFileStore->increment('counter', 5);
            expect($result)->toBe(15);

            $value = $this->keyAwareFileStore->get('counter');
            expect($value)->toBe(15);
        });

        it('increments by 1 by default', function (): void {
            $this->keyAwareFileStore->put('counter', 5, 3600);
            $result = $this->keyAwareFileStore->increment('counter');
            expect($result)->toBe(6);
        });

        it('creates key with value 0 if it does not exist', function (): void {
            $result = $this->keyAwareFileStore->increment('new-counter', 3);
            expect($result)->toBe(3);

            $value = $this->keyAwareFileStore->get('new-counter');
            expect($value)->toBe(3);
        });

        it('handles increment with wrapped data', function (): void {
            $this->keyAwareFileStore->put('wrapped-counter', 20, 3600);
            $result = $this->keyAwareFileStore->increment('wrapped-counter', 10);
            expect($result)->toBe(30);
        });
    });

    describe('backward compatibility', function (): void {
        it('handles legacy unwrapped data format', function (): void {
            // Use reflection to access the protected path() method
            $reflection = new ReflectionClass($this->keyAwareFileStore);
            $pathMethod = $reflection->getMethod('path');

            // Create a legacy cache file (without wrapping) using the correct path
            $legacyPath = $pathMethod->invoke($this->keyAwareFileStore, 'legacy-key');
            $legacyDir = dirname($legacyPath);
            if (! $this->files->exists($legacyDir)) {
                $this->files->makeDirectory($legacyDir, 0755, true);
            }

            $expiration = time() + 3600;
            $legacyData = serialize('legacy-value');
            $this->files->put($legacyPath, $expiration.$legacyData);

            // Should still be able to read it
            $value = $this->keyAwareFileStore->get('legacy-key');
            expect($value)->toBe('legacy-value');
        });

        it('migrates legacy data when writing new wrapped format', function (): void {
            // Use reflection to access the protected path() method
            $reflection = new ReflectionClass($this->keyAwareFileStore);
            $pathMethod = $reflection->getMethod('path');

            // Create legacy file using the correct path
            $legacyPath = $pathMethod->invoke($this->keyAwareFileStore, 'migrate-key');
            $legacyDir = dirname($legacyPath);
            if (! $this->files->exists($legacyDir)) {
                $this->files->makeDirectory($legacyDir, 0755, true);
            }

            $expiration = time() + 3600;
            $legacyData = serialize('old-value');
            $this->files->put($legacyPath, $expiration.$legacyData);

            // Read legacy value
            $oldValue = $this->keyAwareFileStore->get('migrate-key');
            expect($oldValue)->toBe('old-value');

            // Write new value (should use wrapped format)
            $this->keyAwareFileStore->put('migrate-key', 'new-value', 3600);
            $newValue = $this->keyAwareFileStore->get('migrate-key');
            expect($newValue)->toBe('new-value');
        });
    });

    describe('error handling', function (): void {
        it('handles corrupted cache files gracefully', function (): void {
            // Create a corrupted file
            $corruptedPath = $this->cachePath.'/'.md5('corrupted-key');
            $corruptedDir = dirname($corruptedPath);
            if (! $this->files->exists($corruptedDir)) {
                $this->files->makeDirectory($corruptedDir, 0755, true);
            }

            $this->files->put($corruptedPath, 'invalid-serialized-data');

            // Should return null instead of throwing
            $value = $this->keyAwareFileStore->get('corrupted-key');
            expect($value)->toBeNull();
        });

        it('handles files with invalid expiration time', function (): void {
            $invalidPath = $this->cachePath.'/'.md5('invalid-exp-key');
            $invalidDir = dirname($invalidPath);
            if (! $this->files->exists($invalidDir)) {
                $this->files->makeDirectory($invalidDir, 0755, true);
            }

            $this->files->put($invalidPath, 'abc'.serialize(['key' => 'invalid-exp-key', 'value' => 'value']));

            $value = $this->keyAwareFileStore->get('invalid-exp-key');
            expect($value)->toBeNull();
        });

        it('handles files that are too short', function (): void {
            $shortPath = $this->cachePath.'/'.md5('short-key');
            $shortDir = dirname($shortPath);
            if (! $this->files->exists($shortDir)) {
                $this->files->makeDirectory($shortDir, 0755, true);
            }

            $this->files->put($shortPath, '123');

            $value = $this->keyAwareFileStore->get('short-key');
            expect($value)->toBeNull();
        });
    });

    describe('file permissions and directory creation', function (): void {
        it('creates nested directories automatically', function (): void {
            $nestedPath = sys_get_temp_dir().'/cache-ui-laravel-test/nested/test/path';
            $nestedBase = sys_get_temp_dir().'/cache-ui-laravel-test/nested';
            if ($this->files->exists($nestedBase)) {
                $this->files->deleteDirectory($nestedBase);
            }

            $nestedStore = new KeyAwareFileStore($this->files, $nestedPath);
            $result = $nestedStore->put('nested-key', 'nested-value', 3600);
            expect($result)->toBeTrue();
            expect($this->files->exists($nestedPath))->toBeTrue();

            // Cleanup
            $this->files->deleteDirectory($nestedBase);
        });

        it('handles existing directories', function (): void {
            $result = $this->keyAwareFileStore->put('existing-dir-key', 'value', 3600);
            expect($result)->toBeTrue();
        });
    });

    describe('special characters in keys', function (): void {
        it('handles keys with special characters', function (): void {
            $specialKeys = [
                'key-with-dashes',
                'key_with_underscores',
                'key.with.dots',
                'key@with#special$chars',
                'key with spaces',
            ];

            foreach ($specialKeys as $key) {
                $result = $this->keyAwareFileStore->put($key, 'value', 3600);
                expect($result)->toBeTrue();

                $value = $this->keyAwareFileStore->get($key);
                expect($value)->toBe('value');
            }
        });

        it('handles unicode characters in keys', function (): void {
            $unicodeKey = 'clave-con-ñ-y-é';
            $result = $this->keyAwareFileStore->put($unicodeKey, 'valor', 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get($unicodeKey);
            expect($value)->toBe('valor');
        });
    });

    describe('large values', function (): void {
        it('handles large string values', function (): void {
            $largeValue = str_repeat('a', 10000);
            $result = $this->keyAwareFileStore->put('large-key', $largeValue, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('large-key');
            expect($value)->toBe($largeValue);
            expect(mb_strlen($value))->toBe(10000);
        });

        it('handles large array values', function (): void {
            $largeArray = [];
            for ($i = 0; $i < 1000; $i++) {
                $largeArray["key-{$i}"] = "value-{$i}";
            }

            $result = $this->keyAwareFileStore->put('large-array-key', $largeArray, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('large-array-key');
            expect($value)->toBeArray();
            expect(count($value))->toBe(1000);
        });
    });
});
