<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

final class CacheUiLaravel
{
    /**
     * Get all available cache keys
     */
    public function getAllKeys(?string $store = null): array
    {
        $storeName = $store ?? config('cache.default');
        $driver = config("cache.stores.{$storeName}.driver");

        return match ($driver) {
            'redis' => $this->getRedisKeys($storeName),
            'file', 'key-aware-file' => $this->getFileKeys(),
            'database' => $this->getDatabaseKeys(),
            default => []
        };
    }

    /**
     * Delete a specific cache key
     */
    public function forgetKey(string $key, ?string $store = null): bool
    {
        $storeName = $store ?? config('cache.default');
        $cacheStore = $store !== null && $store !== '' && $store !== '0' ? Cache::store($store) : Cache::store();

        if ($cacheStore->forget($key)) {
            return true;
        }

        // Handle file driver specific logic for hashed keys
        $driver = config("cache.stores.{$storeName}.driver");

        if (in_array($driver, ['file', 'key-aware-file']) && preg_match('/^[a-f0-9]{40}$/', $key)) {
            // Use the path from the specific store configuration, fallback to default
            $cachePath = config("cache.stores.{$storeName}.path", config('cache.stores.file.path', storage_path('framework/cache/data')));

            $parts = array_slice(str_split($key, 2), 0, 2);
            $path = $cachePath . '/' . implode('/', $parts) . '/' . $key;

            if (File::exists($path)) {
                return File::delete($path);
            }
        }

        return false;
    }

    private function getRedisKeys(string $store): array
    {
        try {
            $cacheStore = Cache::store($store);
            $prefix = config('database.redis.options.prefix', '');
            $connection = $cacheStore->getStore()->connection();
            $keys = $connection->keys('*');

            return array_map(function ($key) use ($prefix) {
                if ($prefix && str_starts_with($key, (string) $prefix)) {
                    return mb_substr($key, mb_strlen((string) $prefix));
                }

                return $key;
            }, $keys);
        } catch (Exception) {
            return [];
        }
    }

    private function getFileKeys(): array
    {
        try {
            $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));

            if (! File::exists($cachePath)) {
                return [];
            }

            $files = File::allFiles($cachePath);

            return array_map(function (SplFileInfo $file) {
                // Try to read the actual key from the cached value
                $contents = file_get_contents($file->getPathname());

                if (mb_strlen($contents) > 10) {
                    try {
                        $data = unserialize(mb_substr($contents, 10));

                        // Check if it's our wrapped format with the key
                        if (is_array($data) && isset($data['key'])) {
                            return $data['key'];
                        }
                    } catch (Exception) {
                        // Fall through to filename
                    }
                }

                // Default to filename (hash) if we can't read the key
                return $file->getFilename();
            }, $files);
        } catch (Exception) {
            return [];
        }
    }

    private function getDatabaseKeys(): array
    {
        try {
            $table = config('cache.stores.database.table', 'cache');

            return DB::table($table)->pluck('key')->toArray();
        } catch (Exception) {
            return [];
        }
    }
}
