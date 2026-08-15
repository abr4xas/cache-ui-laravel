<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Filesystem\LockTimeoutException;
use Illuminate\Filesystem\LockableFile;

/**
 * A file cache store that records the original key alongside each value.
 *
 * Laravel's FileStore hashes the key into the filename and stores only the
 * value, so the key itself is unrecoverable from disk. This store wraps every
 * payload as `['key' => ..., 'value' => ...]` so Cache UI can list real key
 * names instead of sha1 hashes.
 *
 * Because that changes the on-disk format, every method that reads or writes a
 * payload has to be overridden. Anything that only reads through getPayload()
 * (has, forget, flush, locks) is inherited unchanged.
 */
final class KeyAwareFileStore extends FileStore
{
    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key  The cache key
     * @param  mixed  $value  The value to store
     * @param  int  $seconds  Number of seconds until expiration
     * @return bool True if successful, false otherwise
     */
    public function put($key, $value, $seconds): bool
    {
        $this->ensureCacheDirectoryExists($path = $this->path($key));

        $result = $this->files->put(
            $path, $this->header($seconds).serialize($this->wrap($key, $value)), true
        );

        if ($result !== false && $result > 0) {
            $this->ensurePermissionsAreCorrect($path);

            return true;
        }

        return false;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key  The cache key
     * @return mixed The cached value or null if not found
     */
    public function get($key): mixed
    {
        return $this->unwrap($this->getPayload($key)['data'] ?? null);
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param  string  $key  The cache key
     * @param  mixed  $value  The value to store
     * @param  int  $seconds  Number of seconds until expiration
     * @return bool True if the item was stored, false if key already exists
     */
    public function add($key, $value, $seconds): bool
    {
        $this->ensureCacheDirectoryExists($path = $this->path($key));

        $file = new LockableFile($path, 'c+');

        try {
            $file->getExclusiveLock();
        } catch (LockTimeoutException) {
            $file->close();

            return false;
        }

        $expire = $file->read(10);

        if (empty($expire) || $this->currentTime() >= $expire) {
            $file->truncate()
                ->write($this->header($seconds).serialize($this->wrap($key, $value)))
                ->close();

            $this->ensurePermissionsAreCorrect($path);

            return true;
        }

        $file->close();

        return false;
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key  The cache key
     * @param  mixed  $value  The value to store
     * @return bool True if successful, false otherwise
     */
    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Adjust the expiration time of a cached item.
     *
     * Overridden because the parent re-stores `$payload['data']` verbatim, which
     * for this store is the wrapper array and would end up double-wrapped.
     *
     * @param  string  $key  The cache key
     * @param  int  $seconds  Number of seconds until expiration
     * @return bool True if successful, false otherwise
     */
    public function touch($key, $seconds): bool
    {
        $payload = $this->getPayload($this->getPrefix().$key);

        if (is_null($payload['data'])) {
            return false;
        }

        return $this->put($key, $this->unwrap($payload['data']), $seconds);
    }

    /**
     * Atomically refresh the expiration of a cache key if it matches the expected owner.
     *
     * Used by FileLock::refresh(). The parent reads the payload raw, which for
     * this store is the wrapper array and never matches the expected owner, so
     * the payload has to be unwrapped before the comparison and re-wrapped on
     * write.
     *
     * @param  string  $key  The cache key
     * @param  mixed  $expectedOwner  The owner the lock must currently belong to
     * @param  int  $seconds  Number of seconds until the refreshed expiration
     * @return bool True if the lock was refreshed, false otherwise
     */
    public function refreshIfOwned($key, $expectedOwner, $seconds): bool
    {
        $this->ensureCacheDirectoryExists($path = $this->path($key));

        $file = new LockableFile($path, 'c+');

        try {
            $file->getExclusiveLock();
        } catch (LockTimeoutException) {
            $file->close();

            return false;
        }

        $contents = $file->read();

        if (mb_strlen($contents) < 10) {
            $file->close();

            return false;
        }

        $expire = mb_substr($contents, 0, 10);

        $currentOwner = $this->unwrap($this->unserialize(mb_substr($contents, 10)));

        if ($currentOwner !== $expectedOwner || $this->currentTime() >= $expire) {
            $file->close();

            return false;
        }

        $file->truncate()
            ->write($this->header($seconds).serialize($this->wrap($key, $expectedOwner)))
            ->close();

        $this->ensurePermissionsAreCorrect($path);

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key  The cache key
     * @param  int  $value  The amount to increment by (default: 1)
     * @return int The new value after incrementing
     */
    public function increment($key, $value = 1): mixed
    {
        $raw = $this->getPayload($key);

        $current = (int) $this->unwrap($raw['data'] ?? null);

        return tap($current + $value, function ($newValue) use ($key, $raw): void {
            $this->put($key, $newValue, $raw['time'] ?? 0);
        });
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key  The cache key
     * @param  int  $value  The amount to decrement by (default: 1)
     * @return int The new value after decrementing
     */
    public function decrement($key, $value = 1): mixed
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Build the fixed-width expiration header that precedes the payload.
     *
     * getPayload() reads the expiration with substr($contents, 0, 10), so the
     * header must always be exactly 10 characters wide.
     *
     * @param  int  $seconds  Number of seconds until expiration
     */
    private function header($seconds): string
    {
        return mb_str_pad((string) $this->expiration($seconds), 10, '0', STR_PAD_LEFT);
    }

    /**
     * Wrap a value together with its key for on-disk storage.
     *
     * @param  string  $key  The cache key
     * @param  mixed  $value  The value to store
     * @return array{key: string, value: mixed}
     */
    private function wrap($key, $value): array
    {
        return ['key' => $key, 'value' => $value];
    }

    /**
     * Unwrap a payload written by this store.
     *
     * Payloads written by Laravel's own FileStore, or by an older version of
     * this package, are returned untouched for backwards compatibility.
     *
     * Uses array_key_exists() rather than isset() so a wrapped null value is
     * still recognised as wrapped.
     *
     * @param  mixed  $payload  The raw payload read from disk
     * @return mixed The unwrapped value
     */
    private function unwrap($payload): mixed
    {
        if (is_array($payload) && array_key_exists('key', $payload) && array_key_exists('value', $payload)) {
            return $payload['value'];
        }

        return $payload;
    }
}
