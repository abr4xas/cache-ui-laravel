<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Filesystem\Filesystem;

describe('KeyAwareFileStore Edge Cases', function (): void {
    beforeEach(function (): void {
        $this->files = new Filesystem();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/edge-cases-test';
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

    describe('invalid serialized data', function (): void {
        it('handles invalid serialized data gracefully', function (): void {
            $invalidPath = $this->cachePath.'/'.md5('invalid-serial-key');
            $invalidDir = dirname($invalidPath);
            if (! $this->files->exists($invalidDir)) {
                $this->files->makeDirectory($invalidDir, 0755, true);
            }

            $expiration = time() + 3600;
            $this->files->put($invalidPath, $expiration.'invalid-serialized-data-here');

            $value = $this->keyAwareFileStore->get('invalid-serial-key');
            expect($value)->toBeNull();
        });

        it('handles partially corrupted serialized data', function (): void {
            $corruptedPath = $this->cachePath.'/'.md5('corrupted-serial-key');
            $corruptedDir = dirname($corruptedPath);
            if (! $this->files->exists($corruptedDir)) {
                $this->files->makeDirectory($corruptedDir, 0755, true);
            }

            $expiration = time() + 3600;
            $corruptedData = serialize(['key' => 'corrupted-serial-key', 'value' => 'value']);
            // Corrupt the data
            $corruptedData = mb_substr($corruptedData, 0, -5);
            $this->files->put($corruptedPath, $expiration.$corruptedData);

            $value = $this->keyAwareFileStore->get('corrupted-serial-key');
            expect($value)->toBeNull();
        });
    });

    describe('very large cache values', function (): void {
        it('handles very large string values', function (): void {
            $largeValue = str_repeat('x', 100000); // 100KB
            $result = $this->keyAwareFileStore->put('large-string-key', $largeValue, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('large-string-key');
            expect($value)->toBe($largeValue);
            expect(mb_strlen($value))->toBe(100000);
        });

        it('handles very large array values', function (): void {
            $largeArray = [];
            for ($i = 0; $i < 10000; $i++) {
                $largeArray["key-{$i}"] = str_repeat('v', 100);
            }

            $result = $this->keyAwareFileStore->put('large-array-key', $largeArray, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('large-array-key');
            expect($value)->toBeArray();
            expect(count($value))->toBe(10000);
        });
    });

    describe('special characters in cache keys', function (): void {
        it('handles keys with various special characters', function (): void {
            $specialChars = [
                'key-with-@-symbol',
                'key-with-#-hash',
                'key-with-$-dollar',
                'key-with-%-percent',
                'key-with-&-ampersand',
                'key-with-*-asterisk',
                'key-with-+-plus',
                'key-with-=-equals',
                'key-with-?-question',
                'key-with-!-exclamation',
                'key-with-(-paren',
                'key-with-)-close-paren',
                'key-with-[-bracket',
                'key-with-]-close-bracket',
                'key-with-{-brace',
                'key-with-}-close-brace',
                'key-with-|-pipe',
                'key-with-\\-backslash',
                'key-with-/-slash',
                'key-with-:-colon',
                'key-with-;-semicolon',
                'key-with-"-quote',
                "key-with-'-single-quote",
                'key-with-<-less',
                'key-with->-greater',
                'key-with-,-comma',
                'key-with-.-dot',
            ];

            foreach ($specialChars as $key) {
                $result = $this->keyAwareFileStore->put($key, 'value', 3600);
                expect($result)->toBeTrue();

                $value = $this->keyAwareFileStore->get($key);
                expect($value)->toBe('value');
            }
        });

        it('handles keys with unicode characters', function (): void {
            $unicodeKeys = [
                'clave-con-ñ',
                'ключ-с-кириллицей',
                '鍵-與-漢字',
                '🔑-emoji-key',
                'key-with-é-á-í-ó-ú',
            ];

            foreach ($unicodeKeys as $key) {
                $result = $this->keyAwareFileStore->put($key, 'valor', 3600);
                expect($result)->toBeTrue();

                $value = $this->keyAwareFileStore->get($key);
                expect($value)->toBe('valor');
            }
        });

        it('handles keys with newlines and tabs', function (): void {
            $keyWithNewline = "key-with\nnewline";
            $keyWithTab = "key-with\ttab";

            $result1 = $this->keyAwareFileStore->put($keyWithNewline, 'value1', 3600);
            $result2 = $this->keyAwareFileStore->put($keyWithTab, 'value2', 3600);

            expect($result1)->toBeTrue();
            expect($result2)->toBeTrue();

            $value1 = $this->keyAwareFileStore->get($keyWithNewline);
            $value2 = $this->keyAwareFileStore->get($keyWithTab);

            expect($value1)->toBe('value1');
            expect($value2)->toBe('value2');
        });
    });

    describe('empty and null values', function (): void {
        it('handles empty string values', function (): void {
            $result = $this->keyAwareFileStore->put('empty-string-key', '', 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('empty-string-key');
            expect($value)->toBe('');
        });

        it('handles null values', function (): void {
            $result = $this->keyAwareFileStore->put('null-value-key', null, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('null-value-key');
            expect($value)->toBeNull();
        });

        it('handles empty array values', function (): void {
            $result = $this->keyAwareFileStore->put('empty-array-key', [], 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('empty-array-key');
            expect($value)->toBe([]);
        });

        it('handles zero values', function (): void {
            $result = $this->keyAwareFileStore->put('zero-value-key', 0, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('zero-value-key');
            expect($value)->toBe(0);
        });

        it('handles false boolean values', function (): void {
            $result = $this->keyAwareFileStore->put('false-value-key', false, 3600);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('false-value-key');
            expect($value)->toBeFalse();
        });
    });

    describe('concurrent access simulation', function (): void {
        it('handles rapid put and get operations', function (): void {
            for ($i = 0; $i < 50; $i++) {
                $key = "rapid-key-{$i}";
                $value = "rapid-value-{$i}";
                $this->keyAwareFileStore->put($key, $value, 3600);
                $retrieved = $this->keyAwareFileStore->get($key);
                expect($retrieved)->toBe($value);
            }
        });

        it('handles rapid increment operations', function (): void {
            $this->keyAwareFileStore->put('rapid-counter', 0, 3600);

            for ($i = 0; $i < 100; $i++) {
                $this->keyAwareFileStore->increment('rapid-counter');
            }

            $value = $this->keyAwareFileStore->get('rapid-counter');
            expect($value)->toBe(100);
        });
    });

    describe('expiration edge cases', function (): void {
        it('handles keys that expire exactly at current time', function (): void {
            // Put with expiration at current time (should be expired immediately)
            $this->keyAwareFileStore->put('expired-now-key', 'value', -1);
            $value = $this->keyAwareFileStore->get('expired-now-key');
            expect($value)->toBeNull();
        });

        it('handles very long expiration times', function (): void {
            $longExpiration = 31536000; // 1 year
            $result = $this->keyAwareFileStore->put('long-exp-key', 'value', $longExpiration);
            expect($result)->toBeTrue();

            $value = $this->keyAwareFileStore->get('long-exp-key');
            expect($value)->toBe('value');
        });
    });

    describe('file system edge cases', function (): void {
        it('handles missing cache directory gracefully', function (): void {
            $missingPath = sys_get_temp_dir().'/cache-ui-laravel-test/missing-dir-test';
            if ($this->files->exists($missingPath)) {
                $this->files->deleteDirectory($missingPath);
            }

            $store = new KeyAwareFileStore($this->files, $missingPath);
            $result = $store->put('missing-dir-key', 'value', 3600);
            expect($result)->toBeTrue();
            expect($this->files->exists($missingPath))->toBeTrue();

            // Cleanup
            $this->files->deleteDirectory($missingPath);
        });

        it('handles deeply nested directory structures', function (): void {
            $deepPath = sys_get_temp_dir().'/cache-ui-laravel-test/deep/nested/structure/test';
            $deepBase = sys_get_temp_dir().'/cache-ui-laravel-test/deep';
            if ($this->files->exists($deepBase)) {
                $this->files->deleteDirectory($deepBase);
            }

            $store = new KeyAwareFileStore($this->files, $deepPath);
            $result = $store->put('deep-nested-key', 'value', 3600);
            expect($result)->toBeTrue();

            $value = $store->get('deep-nested-key');
            expect($value)->toBe('value');

            // Cleanup
            $this->files->deleteDirectory($deepBase);
        });
    });
});
