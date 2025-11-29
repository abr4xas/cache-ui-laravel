<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Cache UI Laravel - Main class for cache key management
 *
 * This class provides methods to list, search, and delete cache keys
 * across different cache drivers (Redis, File, Database).
 */
final class CacheUiLaravel
{
    /**
     * Get all available cache keys from the specified store.
     *
     * Supports Redis (with SCAN for production safety), File, and Database drivers.
     * For file driver, requires the `key-aware-file` driver for best results.
     *
     * @param  string|null  $store  The cache store to use (defaults to Laravel's default cache store)
     * @param  int|null  $limit  Maximum number of keys to return (null = unlimited, uses config if not provided)
     * @return array<string> Array of cache key names
     *
     * @example
     * $keys = $cacheUiLaravel->getAllKeys('redis');
     * $limitedKeys = $cacheUiLaravel->getAllKeys('redis', 100);
     */
    public function getAllKeys(?string $store = null, ?int $limit = null): array
    {
        // Validate store name
        $storeName = $store ?? config('cache.default');
        if (empty($storeName)) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::error('Cache UI: Invalid store name', ['store' => $storeName]);
            }

            return [];
        }

        // Validate that store exists
        $stores = config('cache.stores', []);
        if (! isset($stores[$storeName])) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::error('Cache UI: Store does not exist', ['store' => $storeName]);
            }

            return [];
        }

        // Validate limit
        if ($limit !== null && $limit < 0) {
            $limit = null;
        }

        $driver = config("cache.stores.{$storeName}.driver");

        // Use config limit if not provided
        $limit ??= config('cache-ui-laravel.keys_limit');

        $keys = match ($driver) {
            'redis' => $this->getRedisKeys($storeName, $limit),
            'file', 'key-aware-file' => $this->getFileKeys($limit),
            'database' => $this->getDatabaseKeys($limit),
            default => []
        };

        // Apply limit if set
        if ($limit !== null && $limit > 0 && count($keys) > $limit) {
            return array_slice($keys, 0, $limit);
        }

        return $keys;
    }

    /**
     * Delete a specific cache key from the specified store.
     *
     * For file driver, this method will attempt multiple strategies:
     * 1. Standard Laravel cache forget
     * 2. Search for key in file contents (for key-aware-file driver)
     * 3. Delete by filename (for legacy cache files)
     *
     * @param  string  $key  The cache key to delete
     * @param  string|null  $store  The cache store to use (defaults to Laravel's default cache store)
     * @return bool True if the key was deleted, false otherwise
     *
     * @example
     * $deleted = $cacheUiLaravel->forgetKey('user_1_profile', 'redis');
     * $deleted = $cacheUiLaravel->forgetKey('session_data'); // Uses default store
     */
    public function forgetKey(string $key, ?string $store = null): bool
    {
        // Validate key
        if ($key === '' || $key === '0') {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::warning('Cache UI: Attempted to delete empty key');
            }

            return false;
        }

        // Determine store name - treat empty string and '0' as null (use default)
        $storeName = (in_array($store, [null, '', '0'], true)) ? null : $store;
        $storeName ??= config('cache.default');

        // Only validate store if a specific store was provided
        if (! in_array($store, [null, '', '0'], true)) {
            // Validate that store exists
            $stores = config('cache.stores', []);
            if (empty($storeName) || ! isset($stores[$storeName])) {
                if (config('cache-ui-laravel.enable_logging', false)) {
                    Log::error('Cache UI: Store does not exist for forgetKey', ['store' => $storeName]);
                }

                return false;
            }
        }

        $driver = $storeName ? config("cache.stores.{$storeName}.driver") : config('cache.default');
        $cacheStore = (in_array($store, [null, '', '0'], true)) ? Cache::store() : Cache::store($storeName);

        $deleted = $cacheStore->forget($key);

        // For file driver, if standard forget failed, try to delete by searching for the key in files
        if (! $deleted && ($driver === 'file' || $driver === 'key-aware-file')) {
            $deleted = $this->deleteFileKeyByKey($key);

            // If that fails, it might be a legacy cache file where the key is the filename (hash)
            if (! $deleted) {
                $deleted = $this->deleteFileKeyByFilename($key);
            }
        }

        return $deleted;
    }

    private function getRedisKeys(string $store, ?int $limit = null): array
    {
        try {
            $cacheStore = Cache::store($store);
            $prefix = config('database.redis.options.prefix', '');
            $connection = $cacheStore->getStore()->connection();
            $keys = [];

            // Use SCAN instead of KEYS to avoid blocking Redis in production
            // SCAN is safer for production environments
            $cursor = 0;
            $scanCount = 100;

            do {
                $result = $connection->scan($cursor, ['match' => '*', 'count' => $scanCount]);
                $cursor = $result[0];
                $scannedKeys = $result[1] ?? [];

                foreach ($scannedKeys as $key) {
                    $keys[] = $key;

                    // Stop if we've reached the limit
                    if ($limit !== null && $limit > 0 && count($keys) >= $limit) {
                        $cursor = 0; // Break the loop
                        break;
                    }
                }
            } while ($cursor !== 0);

            // If SCAN is not available or fails, fallback to KEYS
            if ($keys === []) {
                try {
                    $allKeys = $connection->keys('*');
                    $keys = $limit !== null && $limit > 0 ? array_slice($allKeys, 0, $limit) : $allKeys;
                } catch (Exception $e) {
                    if (config('cache-ui-laravel.enable_logging', false)) {
                        Log::warning('Cache UI: Failed to get Redis keys using KEYS fallback', [
                            'store' => $store,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    return [];
                }
            }

            return array_map(function ($key) use ($prefix) {
                if ($prefix && str_starts_with((string) $key, (string) $prefix)) {
                    return mb_substr((string) $key, mb_strlen((string) $prefix));
                }

                return $key;
            }, $keys);
        } catch (Exception $e) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::error('Cache UI: Error getting Redis keys', [
                    'store' => $store,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
    }

    private function getFileKeys(?int $limit = null): array
    {
        try {
            $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));

            if (! File::exists($cachePath)) {
                return [];
            }

            $files = File::allFiles($cachePath);
            $keys = [];
            $count = 0;

            foreach ($files as $file) {
                // Stop if we've reached the limit
                if ($limit !== null && $limit > 0 && $count >= $limit) {
                    break;
                }

                try {
                    // Try to read the actual key from the cached value
                    $contents = file_get_contents($file->getPathname());

                    if (mb_strlen($contents) > 10) {
                        try {
                            $expiration = mb_substr($contents, 0, 10);

                            // Check if expired
                            if (time() > $expiration) {
                                continue;
                            }

                            $data = unserialize(mb_substr($contents, 10));

                            // Check if it's our wrapped format with the key
                            if (is_array($data) && isset($data['key'])) {
                                $keys[] = $data['key'];
                                $count++;

                                continue;
                            }
                        } catch (Exception) {
                            // Fall through to filename
                        }
                    }

                    // Default to filename (hash) if we can't read the key
                    $keys[] = $file->getFilename();
                    $count++;
                } catch (Exception) {
                    // Skip files we can't read
                    continue;
                }
            }

            return $keys;
        } catch (Exception $e) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::error('Cache UI: Error getting file cache keys', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
    }

    private function getDatabaseKeys(?int $limit = null): array
    {
        try {
            $table = config('cache.stores.database.table', 'cache');
            $query = DB::table($table);

            if ($limit !== null && $limit > 0) {
                $query->limit($limit);
            }

            return $query->pluck('key')->toArray();
        } catch (Exception $e) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::error('Cache UI: Error getting database cache keys', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
    }

    /**
     * Delete a file cache key by searching for the actual key in file contents
     */
    private function deleteFileKeyByKey(string $key): bool
    {
        try {
            $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));

            if (! File::exists($cachePath)) {
                return false;
            }

            $files = File::allFiles($cachePath);

            foreach ($files as $file) {
                try {
                    $content = File::get($file->getPathname());

                    // Laravel file cache format: expiration_time + serialized_value
                    if (mb_strlen($content) < 10) {
                        continue;
                    }

                    $expiration = mb_substr($content, 0, 10);

                    // Check if expired
                    if (time() > $expiration) {
                        continue;
                    }

                    $serialized = mb_substr($content, 10);

                    // Try to unserialize to get the data
                    $data = unserialize($serialized);
                    if (is_array($data) && isset($data['key']) && $data['key'] === $key) {
                        return File::delete($file->getPathname());
                    }
                } catch (Exception) {
                    // If we can't read this file, skip it
                    continue;
                }
            }

            return false;
        } catch (Exception $e) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::warning('Cache UI: Error deleting file cache key by key', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Delete a file cache key by filename (for legacy cache files)
     */
    private function deleteFileKeyByFilename(string $filename): bool
    {
        try {
            $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));
            $filePath = $cachePath.'/'.$filename;

            if (File::exists($filePath)) {
                return File::delete($filePath);
            }

            return false;
        } catch (Exception $e) {
            if (config('cache-ui-laravel.enable_logging', false)) {
                Log::warning('Cache UI: Error deleting file cache key by filename', [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }
}
