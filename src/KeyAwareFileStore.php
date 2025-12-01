<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Filesystem\LockTimeoutException;
use Illuminate\Filesystem\LockableFile;

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
        // Wrap the value with the key for Cache UI visibility
        $wrappedValue = [
            'key' => $key,
            'value' => $value,
        ];

        $this->ensureCacheDirectoryExists($path = $this->path($key));

        $result = $this->files->put(
            $path, $this->expiration($seconds).serialize($wrappedValue), true
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
        $payload = $this->getPayload($key)['data'] ?? null;

        // Unwrap the value if it's in our format
        // Use array_key_exists instead of isset to handle null values correctly
        if (is_array($payload) && array_key_exists('key', $payload) && array_key_exists('value', $payload)) {
            return $payload['value'];
        }

        // Return as-is for backwards compatibility
        return $payload;
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
        // Wrap the value with the key
        $wrappedValue = [
            'key' => $key,
            'value' => $value,
        ];

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
                ->write($this->expiration($seconds).serialize($wrappedValue))
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
     * Increment the value of an item in the cache.
     *
     * @param  string  $key  The cache key
     * @param  int  $value  The amount to increment by (default: 1)
     * @return int The new value after incrementing
     */
    public function increment($key, $value = 1): mixed
    {
        $raw = $this->getPayload($key);
        $data = $raw['data'] ?? null;

        // Unwrap if needed
        $currentValue = is_array($data) && isset($data['value']) ? (int) $data['value'] : (int) $data;

        return tap($currentValue + $value, function ($newValue) use ($key, $raw): void {
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
        $raw = $this->getPayload($key);
        $data = $raw['data'] ?? null;

        // Unwrap if needed
        $currentValue = is_array($data) && isset($data['value']) ? (int) $data['value'] : (int) $data;

        return tap($currentValue - $value, function ($newValue) use ($key, $raw): void {
            $this->put($key, $newValue, $raw['time'] ?? 0);
        });
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @param  string  $key  The cache key
     * @param  DateTimeInterface|DateInterval|int|null  $ttl  Time to live
     * @param  Closure  $callback  The closure to execute if key doesn't exist
     * @return mixed The cached value or the result of the callback
     */
    public function remember($key, $ttl, $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @param  string  $key  The cache key
     * @param  Closure  $callback  The closure to execute if key doesn't exist
     * @return mixed The cached value or the result of the callback
     */
    public function rememberForever($key, $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->forever($key, $value);

        return $value;
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param  string  $key  The cache key
     * @param  mixed  $default  Default value if key doesn't exist
     * @return mixed The cached value or the default value
     */
    public function pull($key, $default = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            $this->forget($key);

            return $value;
        }

        return $default;
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @param  string  $key  The cache key
     * @return bool True if the key exists, false otherwise
     */
    public function has($key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool True if successful, false otherwise
     */
    public function flush(): bool
    {
        if (! $this->files->isDirectory($this->directory)) {
            return false;
        }

        foreach ($this->files->directories($this->directory) as $directory) {
            $deleted = $this->files->deleteDirectory($directory);

            if (! $deleted || $this->files->exists($directory)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure the cache directory exists.
     *
     * @param  string  $path  The file path
     */
    protected function ensureCacheDirectoryExists($path): void
    {
        if (! $this->files->exists($directory = dirname($path))) {
            $this->files->makeDirectory($directory, 0755, true);
        }
    }
}
