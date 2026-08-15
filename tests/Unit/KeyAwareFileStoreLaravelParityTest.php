<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Cache\FileLock;
use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;

/**
 * These tests pin the behaviour KeyAwareFileStore inherits from, or deliberately
 * diverges from, Illuminate\Cache\FileStore. The store overrides several methods
 * that read and write the raw on-disk payload, so any upstream change to that
 * format is a silent breakage here. Each test states the Laravel behaviour it
 * mirrors so a future framework upgrade fails loudly instead of quietly.
 */
describe('KeyAwareFileStore parity with Laravel FileStore', function (): void {
    beforeEach(function (): void {
        $this->files = new Filesystem();
        $this->cachePath = sys_get_temp_dir().'/cache-ui-laravel-test/parity-'.getmypid();

        if ($this->files->exists($this->cachePath)) {
            $this->files->deleteDirectory($this->cachePath);
        }

        $this->store = new KeyAwareFileStore($this->files, $this->cachePath);
    });

    afterEach(function (): void {
        if ($this->files->exists($this->cachePath)) {
            $this->files->deleteDirectory($this->cachePath);
        }
    });

    describe('on-disk payload format', function (): void {
        it('writes a 10 character expiration header exactly like FileStore', function (): void {
            $this->store->put('parity-key', 'value', 60);

            $contents = $this->files->get($this->store->path('parity-key'));

            // FileStore::getPayload() reads the expiration with substr($contents, 0, 10),
            // so the header must always be exactly 10 characters wide.
            expect(mb_substr($contents, 0, 10))->toHaveLength(10)
                ->and(mb_substr($contents, 0, 10))->toMatch('/^\d{10}$/');
        });

        it('writes a 10 character expiration header from add() as well', function (): void {
            $this->store->add('parity-add', 'value', 60);

            $contents = $this->files->get($this->store->path('parity-add'));

            expect(mb_substr($contents, 0, 10))->toMatch('/^\d{10}$/');
        });

        it('uses the same path hashing scheme as FileStore', function (): void {
            $laravelStore = new FileStore($this->files, $this->cachePath);

            expect($this->store->path('some-key'))->toBe($laravelStore->path('some-key'));
        });
    });

    describe('lock support', function (): void {
        it('can refresh a lock it acquired', function (): void {
            /** @var FileLock $lock */
            $lock = $this->store->lock('parity-lock', 10);

            expect($lock->acquire())->toBeTrue()
                // FileLock::refresh() delegates to FileStore::refreshIfOwned(), which
                // compares the raw unserialized payload against the lock owner. The
                // store wraps every payload, so refreshIfOwned() must unwrap it too.
                ->and($lock->refresh(30))->toBeTrue();
        });

        it('refuses to refresh a lock owned by someone else', function (): void {
            /** @var FileLock $lock */
            $lock = $this->store->lock('parity-lock-other', 10);
            $lock->acquire();

            /** @var FileLock $intruder */
            $intruder = $this->store->lock('parity-lock-other', 10);

            expect($intruder->refresh(30))->toBeFalse();
        });

        it('round-trips the lock owner through the wrapped payload', function (): void {
            /** @var FileLock $lock */
            $lock = $this->store->lock('parity-lock-owner', 10);
            $lock->acquire();

            // CacheLock::isOwnedByCurrentProcess() reads the value back via get().
            expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        });
    });

    describe('directory creation', function (): void {
        it('applies the configured file permission to created directories', function (): void {
            $store = new KeyAwareFileStore($this->files, $this->cachePath, 0777);
            $store->put('perm-key', 'value', 60);

            $directory = dirname($store->path('perm-key'));

            // FileStore::ensureCacheDirectoryExists() chmods both created levels
            // when a file permission is configured.
            expect(mb_substr(sprintf('%o', fileperms($directory)), -4))->toBe('0777');
        });
    });

    describe('value unwrapping', function (): void {
        it('unwraps a stored null value', function (): void {
            $this->store->put('null-key', null, 60);

            expect($this->store->get('null-key'))->toBeNull();
        });

        it('does not mistake a legacy array payload for a wrapped one', function (): void {
            // A user array that happens to carry a "value" key must survive intact.
            $this->store->put('array-key', ['value' => 'inner', 'other' => 1], 60);

            expect($this->store->get('array-key'))->toBe(['value' => 'inner', 'other' => 1]);
        });

        it('increments a wrapped counter without unwrapping the wrong field', function (): void {
            $this->store->put('counter', 5, 60);

            expect($this->store->increment('counter'))->toBe(6)
                ->and($this->store->get('counter'))->toBe(6);
        });

        it('decrements a wrapped counter', function (): void {
            $this->store->put('counter-down', 5, 60);

            expect($this->store->decrement('counter-down', 2))->toBe(3)
                ->and($this->store->get('counter-down'))->toBe(3);
        });

        it('treats a wrapped null as zero when incrementing', function (): void {
            $this->store->put('null-counter', null, 60);

            // A null payload must unwrap to null and cast to 0, not fall through
            // to casting the wrapper array itself (which yields 1).
            expect($this->store->increment('null-counter'))->toBe(1);
        });

        it('restores the expiration window when touching a wrapped value', function (): void {
            $this->store->put('touch-key', 'payload', 60);

            expect($this->store->touch('touch-key', 120))->toBeTrue()
                // Without unwrapping, touch() would re-store the wrapper and
                // get() would hand back the inner array instead of the value.
                ->and($this->store->get('touch-key'))->toBe('payload');
        });
    });
});
