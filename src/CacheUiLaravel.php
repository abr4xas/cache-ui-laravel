<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Cache UI Laravel - Main class for cache key management
 *
 * This class provides methods to list, search, and delete cache keys
 * across different cache drivers (Redis, File, Database).
 */
final class CacheUiLaravel
{
    /**
     * Prefix Laravel uses for the companion timestamp entry written by Cache::flexible().
     *
     * The "stale-while-revalidate" helper stores an
     * `illuminate:cache:flexible:created:<key>` entry next to each value to track
     * its freshness. That entry is internal bookkeeping, not a user key.
     *
     * @see \Illuminate\Cache\Repository::FLEXIBLE_CREATED_KEY_PREFIX
     */
    private const string FLEXIBLE_CREATED_KEY_PREFIX = 'illuminate:cache:flexible:created:';

    /**
     * Get all available cache keys from the specified store.
     *
     * Supports Redis (with SCAN for production safety), File, and Database drivers.
     * For file driver, requires the `key-aware-file` driver for best results.
     *
     * @param  string|null  $store  The cache store to use (defaults to Laravel's default cache store)
     * @param  int|null  $limit  Maximum number of keys to return (null = unlimited, uses config if not provided)
     * @param  int  $offset  Number of keys to skip before returning results (for pagination)
     * @return array<string> Array of cache key names
     *
     * @example
     * $keys = $cacheUiLaravel->getAllKeys('redis');
     * $limitedKeys = $cacheUiLaravel->getAllKeys('redis', 100);
     * $page2 = $cacheUiLaravel->getAllKeys('redis', 100, 100);
     */
    public function getAllKeys(?string $store = null, ?int $limit = null, int $offset = 0): array
    {
        // Validate store name
        $storeName = $store ?? config('cache.default');
        if (empty($storeName)) {
            $this->log('error', 'Cache UI: Invalid store name', ['store' => $storeName]);

            return [];
        }

        // Validate that store exists
        $stores = config('cache.stores', []);
        if (! isset($stores[$storeName])) {
            $this->log('error', 'Cache UI: Store does not exist', ['store' => $storeName]);

            return [];
        }

        // Validate limit
        if ($limit !== null && $limit < 0) {
            $limit = null;
        }

        // Validate offset
        if ($offset < 0) {
            $offset = 0;
        }

        // Use config limit if not provided
        $limit ??= config('cache-ui-laravel.keys_limit');

        // Resolve the store once and read the cache path, table, connection and
        // key prefix off the store itself. Re-deriving those from config by a
        // hardcoded path is what used to break stores not literally named "file"
        // or "database", stores on a non-default database connection, and any
        // non-empty cache prefix.
        $baseStore = $this->resolveStore($storeName);

        if (! $baseStore instanceof Store) {
            return [];
        }

        $keys = match (true) {
            $baseStore instanceof RedisStore => $this->getRedisKeys($baseStore, $limit, $offset),
            // KeyAwareFileStore extends FileStore, so both file drivers land here.
            $baseStore instanceof FileStore => $this->getFileKeys($baseStore, $limit, $offset),
            $baseStore instanceof DatabaseStore => $this->getDatabaseKeys($baseStore, $storeName, $limit, $offset),
            default => [],
        };

        // Hide Laravel's internal bookkeeping keys (e.g. the companion entries
        // written by Cache::flexible()) so they don't clutter the listing. When
        // enabled, $limit acts as a soft cap since these are filtered afterwards.
        if (config('cache-ui-laravel.hide_internal_keys', true)) {
            return $this->filterInternalKeys($keys);
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
            $this->log('warning', 'Cache UI: Attempted to delete empty key');

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
                $this->log('error', 'Cache UI: Store does not exist for forgetKey', ['store' => $storeName]);

                return false;
            }
        }

        $cacheStore = (in_array($store, [null, '', '0'], true)) ? Cache::store() : Cache::store($storeName);

        $deleted = $cacheStore->forget($key);

        // For file stores, if the standard forget failed, fall back to scanning the
        // cache directory. The directory comes from the store rather than config so
        // the fallback follows whichever store was actually resolved.
        $baseStore = $cacheStore->getStore();

        if (! $deleted && $baseStore instanceof FileStore) {
            // First try to find and delete by key content (for key-aware-file driver)
            $deleted = $this->deleteFileKeyByKey($baseStore, $key);

            // If that fails, try to delete by filename (handles both hashed and direct filenames)
            if (! $deleted) {
                $deleted = $this->deleteFileKeyByFilename($baseStore, $key);
            }
        }

        return $deleted;
    }

    /**
     * Write a log line, but only when the package has logging turned on.
     *
     * Every failure path in this class is best-effort: it degrades to an empty
     * result rather than surfacing an exception. Routing those through one place
     * keeps the `enable_logging` check from being restated at each call site.
     *
     * @param  string  $level  A PSR-3 log level ("error", "warning", ...)
     * @param  string  $message  The message to log
     * @param  array<string, mixed>  $context  Additional context for the log entry
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (config('cache-ui-laravel.enable_logging', false)) {
            Log::log($level, $message, $context);
        }
    }

    /**
     * Remove Laravel's internal cache bookkeeping keys from a key list.
     *
     * Currently this hides the companion entries written by Cache::flexible()
     * (the "stale-while-revalidate" helper), which stores an
     * `illuminate:cache:flexible:created:<key>` timestamp alongside each value.
     *
     * @param  array<string>  $keys  The raw key list to filter
     * @return array<string> The list with internal keys removed and re-indexed
     *
     * @example
     * $clean = $this->filterInternalKeys(['users', 'illuminate:cache:flexible:created:users']);
     * // ['users']
     */
    private function filterInternalKeys(array $keys): array
    {
        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ! str_starts_with($key, self::FLEXIBLE_CREATED_KEY_PREFIX)
        ));
    }

    /**
     * Retrieve cache keys from a Redis store.
     *
     * Uses Redis SCAN (cursor-based, non-blocking) to remain production-safe,
     * falling back to KEYS only if SCAN yields nothing. The configured Redis
     * prefix is stripped from each key before the offset and limit are applied.
     *
     * @param  RedisStore  $store  The resolved Redis cache store
     * @param  int|null  $limit  Maximum number of keys to return (null = unlimited)
     * @param  int  $offset  Number of keys to skip before returning results
     * @return array<string> The matching cache key names, with every prefix stripped
     *
     * @example
     * $keys = $this->getRedisKeys($store);         // All keys
     * $page = $this->getRedisKeys($store, 50, 50);  // 50 keys, skipping the first 50
     */
    private function getRedisKeys(RedisStore $store, ?int $limit = null, int $offset = 0): array
    {
        try {
            $connection = $store->connection();
            $prefix = $this->redisKeyPrefix($store);

            $keys = [];
            $totalNeeded = $offset + ($limit ?? 0);

            // phpredis 6.1 and later expect the iteration to start from a null
            // cursor; older clients and Predis start from '0'. Mirrors the same
            // decision in Illuminate\Cache\RedisStore::currentTags().
            $cursor = $connection instanceof PhpRedisConnection
                && version_compare((string) phpversion('redis'), '6.1.0', '>=')
                    ? null
                    : '0';

            // Use SCAN instead of KEYS to avoid blocking Redis in production.
            // Matching on the prefix keeps the scan to this store's own keys
            // instead of everything sharing the Redis database.
            do {
                // Laravel's Redis Connection is annotated `@mixin \Redis`, so static
                // analysis resolves scan() to the raw PhpRedis signature rather than
                // PhpRedisConnection::scan($cursor, $options), which returns a
                // [cursor, keys] pair or false. Spelling the real shape out here keeps
                // the runtime guards below meaningful instead of "always false".
                /** @var array{0: mixed, 1: mixed}|false $scanResult */
                $scanResult = $connection->scan($cursor, ['match' => $prefix.'*', 'count' => 100]);

                if (! is_array($scanResult)) {
                    break;
                }

                [$cursor, $scannedKeys] = $scanResult;

                if (! is_array($scannedKeys)) {
                    break;
                }

                foreach ($scannedKeys as $key) {
                    $keys[] = (string) $key;
                }

                // Stop once we've collected enough keys (offset + limit)
                if ($limit !== null && $limit > 0 && count($keys) >= $totalNeeded) {
                    break;
                }

                // Redis signals a completed iteration by handing back a zero
                // cursor (null on phpredis 6.1+). Testing for that directly,
                // rather than comparing against the cursor we started from,
                // keeps the loop terminating whichever client is in play.
            } while (! in_array((string) $cursor, ['0', ''], true));

            // If SCAN is not available or yields nothing, fall back to KEYS
            if ($keys === []) {
                try {
                    $keys = array_map(strval(...), (array) $connection->keys($prefix.'*'));
                } catch (Throwable $e) {
                    $this->log('warning', 'Cache UI: Failed to get Redis keys using KEYS fallback', [
                        'store' => $store->getPrefix(),
                        'error' => $e->getMessage(),
                    ]);

                    return [];
                }
            }

            // chopStart() only removes the prefix when it really is at the start,
            // so a key that merely contains the prefix is left intact.
            $keys = array_map(
                static fn (string $key): string => Str::chopStart($key, $prefix),
                $keys
            );

            // Apply offset and limit
            if ($offset > 0) {
                $keys = array_slice($keys, $offset);
            }

            if ($limit !== null && $limit > 0) {
                $keys = array_slice($keys, 0, $limit);
            }

            return array_values($keys);
        } catch (Throwable $e) {
            $this->log('error', 'Cache UI: Error getting Redis keys', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the full key prefix that Redis SCAN and KEYS results carry.
     *
     * Two prefixes stack up on a Redis cache key: the cache store's own prefix
     * (from `cache.prefix`) and any global prefix the Redis connection applies
     * on top of it. SCAN and KEYS return the fully prefixed key, so both have to
     * come off again before the key is usable with Cache::forget().
     *
     * Mirrors the prefix computation in Illuminate\Cache\RedisStore::currentTags().
     *
     * @param  RedisStore  $store  The resolved Redis cache store
     * @return string The concatenated connection and store prefix
     */
    private function redisKeyPrefix(RedisStore $store): string
    {
        $connection = $store->connection();

        $connectionPrefix = match (true) {
            $connection instanceof PhpRedisConnection => $connection->_prefix(''),
            $connection instanceof PredisConnection => (string) ($connection->getOptions()->prefix ?: ''),
            default => '',
        };

        return $connectionPrefix.$store->getPrefix();
    }

    /**
     * Resolve a cache store by name, returning null if it cannot be built.
     *
     * @param  string  $storeName  The cache store name
     * @return Store|null The resolved store, or null on failure
     */
    private function resolveStore(string $storeName): ?Store
    {
        try {
            return Cache::store($storeName)->getStore();
        } catch (Throwable $e) {
            $this->log('error', 'Cache UI: Could not resolve cache store', [
                'store' => $storeName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve cache keys from the file cache store.
     *
     * Reads each cache file under the configured cache path. For entries written
     * by the `key-aware-file` driver the real key is returned; otherwise the
     * hashed filename is used as a fallback. Expired and unreadable files are
     * skipped before the offset and limit are applied.
     *
     * @param  FileStore  $store  The resolved file cache store
     * @param  int|null  $limit  Maximum number of keys to return (null = unlimited)
     * @param  int  $offset  Number of keys to skip before returning results
     * @return array<string> The cache key names (real keys or hashed filenames)
     *
     * @example
     * $keys = $this->getFileKeys($store);       // All file cache keys
     * $page = $this->getFileKeys($store, 25, 0); // First 25 keys
     */
    private function getFileKeys(FileStore $store, ?int $limit = null, int $offset = 0): array
    {
        try {
            $cachePath = $store->getDirectory();

            if (! File::exists($cachePath)) {
                return [];
            }

            // Lazily so the walk stops as soon as the limit is satisfied instead of
            // reading and unserializing every cache file first.
            $keys = LazyCollection::make(File::allFiles($cachePath))
                ->map(fn (SplFileInfo $file): ?string => $this->readCacheKey($file))
                // Expired and unreadable entries drop out before paging, so the
                // offset counts real keys rather than files on disk.
                ->reject(static fn (?string $key): bool => $key === null)
                ->skip($offset);

            if ($limit !== null && $limit > 0) {
                $keys = $keys->take($limit);
            }

            return $keys->values()->all();
        } catch (Throwable $e) {
            $this->log('error', 'Cache UI: Error getting file cache keys', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Read the cache key a single file represents.
     *
     * Files written by the `key-aware-file` driver carry the original key inside
     * the payload. Anything else -- entries from Laravel's own file store, or a
     * payload we cannot make sense of -- falls back to the hashed filename, which
     * is still enough for forgetKey() to delete it.
     *
     * @param  SplFileInfo  $file  The cache file to inspect
     * @param  bool  $fallbackToFilename  When false, only a genuine wrapped key is
     *                                    returned; used when matching a specific key
     *                                    rather than building a listing.
     * @return string|null The cache key, or null if the entry is expired or unreadable
     */
    private function readCacheKey(SplFileInfo $file, bool $fallbackToFilename = true): ?string
    {
        try {
            $contents = File::get($file->getPathname());
        } catch (Throwable) {
            // Unreadable file: leave it out of the listing entirely.
            return null;
        }

        try {
            // Laravel's file cache format is a 10 character expiration header
            // followed by the serialized value.
            if (mb_strlen($contents) > 10) {
                if (time() > (int) mb_substr($contents, 0, 10)) {
                    return null;
                }

                // Only the wrapped key is needed here, never the value, so refuse
                // to revive objects while listing.
                $data = unserialize(mb_substr($contents, 10), ['allowed_classes' => false]);

                if (is_array($data) && isset($data['key'])) {
                    return (string) $data['key'];
                }
            }
        } catch (Throwable) {
            // Corrupted payload: fall through to the filename.
        }

        return $fallbackToFilename ? $file->getFilename() : null;
    }

    /**
     * Retrieve cache keys from the database cache store.
     *
     * Reads the `key` column from the configured cache table, applying the
     * offset and limit at the query level for efficient pagination.
     *
     * @param  DatabaseStore  $store  The resolved database cache store
     * @param  string  $storeName  The cache store name, used to look up its table
     * @param  int|null  $limit  Maximum number of keys to return (null = unlimited)
     * @param  int  $offset  Number of keys to skip before returning results
     * @return array<string> The cache key names, with the store prefix stripped
     *
     * @example
     * $keys = $this->getDatabaseKeys($store, 'database');        // All database cache keys
     * $page = $this->getDatabaseKeys($store, 'database', 100, 0); // First 100 keys
     */
    private function getDatabaseKeys(DatabaseStore $store, string $storeName, ?int $limit = null, int $offset = 0): array
    {
        try {
            // DatabaseStore exposes its connection and prefix but not its table,
            // so the table still comes from config -- keyed by the store that was
            // actually resolved rather than a hardcoded "database".
            $table = config("cache.stores.{$storeName}.table", 'cache');
            $query = $store->getConnection()->table($table);

            if ($offset > 0) {
                $query->offset($offset);
            }

            if ($limit !== null && $limit > 0) {
                $query->limit($limit);
            }

            $prefix = $store->getPrefix();

            // The key column holds the prefixed key. Cache::forget() re-applies the
            // prefix, so it has to come off here or the round trip double-prefixes
            // and silently deletes nothing.
            return $query->pluck('key')
                ->map(static fn ($key): string => Str::chopStart((string) $key, $prefix))
                ->values()
                ->all();
        } catch (Throwable $e) {
            $this->log('error', 'Cache UI: Error getting database cache keys', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Delete a file cache entry by matching the real key stored in its contents.
     *
     * Intended for the `key-aware-file` driver, which wraps the original key
     * inside the cached payload. Each non-expired cache file is unserialized and
     * its stored key compared to the given key; the first match is deleted.
     *
     * @param  FileStore  $store  The resolved file cache store
     * @param  string  $key  The original (unhashed) cache key to delete
     * @return bool True if a matching cache file was found and deleted, false otherwise
     *
     * @example
     * $deleted = $this->deleteFileKeyByKey($store, 'user_1_profile');
     */
    private function deleteFileKeyByKey(FileStore $store, string $key): bool
    {
        try {
            $cachePath = $store->getDirectory();

            if (! File::exists($cachePath)) {
                return false;
            }

            // first() stops reading as soon as the key is found. The filename
            // fallback is disabled here so only a genuinely wrapped key matches;
            // hashed filenames are the job of deleteFileKeyByFilename().
            $match = LazyCollection::make(File::allFiles($cachePath))
                ->first(fn (SplFileInfo $file): bool => $this->readCacheKey($file, fallbackToFilename: false) === $key);

            return $match instanceof SplFileInfo && File::delete($match->getPathname());
        } catch (Throwable $e) {
            $this->log('warning', 'Cache UI: Error deleting file cache key by key', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a file cache entry by its filename (for legacy cache files).
     *
     * Handles both Laravel's SHA1-hashed keys — stored under a nested
     * `ab/cd/<hash>` directory structure — and plain, non-hashed filenames.
     *
     * @param  FileStore  $store  The resolved file cache store
     * @param  string  $filename  The cache filename or SHA1 hash to delete
     * @return bool True if the file existed and was deleted, false otherwise
     *
     * @example
     * $deleted = $this->deleteFileKeyByFilename($store, '356a192b7913b04c54574d18c28d46e6395428ab');
     */
    private function deleteFileKeyByFilename(FileStore $store, string $filename): bool
    {
        try {
            $cachePath = $store->getDirectory();

            // Check if filename is a SHA1 hash (40 hex characters)
            // Laravel stores hashed keys in directory structure: first 2 chars / next 2 chars / full hash
            if (mb_strlen($filename) === 40 && ctype_xdigit($filename)) {
                $subDir = mb_substr($filename, 0, 2).'/'.mb_substr($filename, 2, 2);
                $filePath = $cachePath.'/'.$subDir.'/'.$filename;

                if (File::exists($filePath)) {
                    return File::delete($filePath);
                }
            }

            // Try direct path (for non-hashed filenames)
            $filePath = $cachePath.'/'.$filename;
            if (File::exists($filePath)) {
                return File::delete($filePath);
            }

            return false;
        } catch (Throwable $e) {
            $this->log('warning', 'Cache UI: Error deleting file cache key by filename', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
