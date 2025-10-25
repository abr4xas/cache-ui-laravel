<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Filesystem\LockTimeoutException;
use Illuminate\Filesystem\LockableFile;

final class KeyAwareFileStore extends FileStore
{
    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
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
     * @param  string  $key
     */
    public function get($key): mixed
    {
        $payload = $this->getPayload($key)['data'] ?? null;

        // Unwrap the value if it's in our format
        if (is_array($payload) && isset($payload['key']) && isset($payload['value'])) {
            return $payload['value'];
        }

        // Return as-is for backwards compatibility
        return $payload;
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
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
     * @param  string  $key
     * @param  mixed  $value
     */
    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
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
     * Ensure the cache directory exists.
     *
     * @param  string  $path
     */
    protected function ensureCacheDirectoryExists($path): void
    {
        if (! $this->files->exists($directory = dirname($path))) {
            $this->files->makeDirectory($directory, 0755, true);
        }
    }
}
