<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\search;
use function Laravel\Prompts\warning;

final class CacheUiLaravelCommand extends Command
{
    public $signature = 'cache:list {--store= : The cache store to use}';

    public $description = 'List and delete individual cache keys';

    private string $driver;

    private mixed $store;

    public function handle(): int
    {
        $storeName = $this->option('store') ?? config('cache-ui-laravel.default_store') ?? config('cache.default');
        $this->store = Cache::store($storeName);
        $this->driver = config("cache.stores.{$storeName}.driver");

        info("📦 Cache driver: {$this->driver}");

        $keys = $this->getCacheKeys();

        if ($keys === []) {
            warning('⚠️  No cache keys found.');

            return self::SUCCESS;
        }

        info('✅ Found '.count($keys).' cache keys');

        $searchScroll = config('cache-ui-laravel.search_scroll', 15);

        $selectedKey = search(
            label: '🔍 Search and select a cache key to delete',
            options: fn (string $value): array => mb_strlen($value) > 0
                ? array_filter($keys, fn ($key): bool => str_contains(mb_strtolower((string) $key), mb_strtolower($value)))
                : $keys,
            placeholder: 'Type to search...',
            scroll: $searchScroll
        );

        if ($selectedKey === 0 || ($selectedKey === '' || $selectedKey === '0')) {
            info('👋 Operation cancelled');

            return self::SUCCESS;
        }

        // Get the key value to show information
        $value = $this->store->get($selectedKey);

        // If value is null, try different approaches based on driver
        if ($value === null) {
            if ($this->driver === 'redis') {
                // For Redis, try with prefix
                $prefix = config('database.redis.options.prefix', '');
                if ($prefix) {
                    $value = $this->store->get($prefix.$selectedKey);
                }
            } elseif ($this->driver === 'file') {
                // For file driver, the selectedKey might be a filename hash
                // We need to find the actual key by checking all files
                $value = $this->getFileKeyValue($selectedKey);
            }
        }

        $valuePreview = $this->getValuePreview($value);

        $this->newLine();
        $this->line("📝 <fg=cyan>Key:</>      {$selectedKey}");
        $this->line("💾 <fg=cyan>Value:</>    {$valuePreview}");
        $this->newLine();

        $confirmed = confirm(
            label: 'Are you sure you want to delete this cache key?',
            default: false
        );

        if (! $confirmed) {
            info('👋 Operation cancelled');

            return self::SUCCESS;
        }

        // Try to delete the key
        $deleted = $this->store->forget($selectedKey);

        // If not deleted, try different approaches based on driver
        if (! $deleted) {
            if ($this->driver === 'redis') {
                // For Redis, try with prefix
                $prefix = config('database.redis.options.prefix', '');
                if ($prefix) {
                    $deleted = $this->store->forget($prefix.$selectedKey);
                }
            } elseif ($this->driver === 'file') {
                // For file driver, try to delete using the actual key
                $deleted = $this->deleteFileKey($selectedKey);
            }
        }

        if ($deleted) {
            info("🗑️  The key '{$selectedKey}' has been successfully deleted");

            return self::SUCCESS;
        }

        error("❌ Could not delete the key '{$selectedKey}'");

        return self::FAILURE;
    }

    private function getCacheKeys(): array
    {
        return match ($this->driver) {
            'redis' => $this->getRedisKeys(),
            'file' => $this->getFileKeys(),
            'database' => $this->getDatabaseKeys(),
            'array' => $this->getArrayKeys(),
            default => $this->handleUnsupportedDriver()
        };
    }

    private function getRedisKeys(): array
    {
        try {
            $prefix = config('database.redis.options.prefix', '');
            $connection = $this->store->getStore()->connection();
            $keys = $connection->keys('*');

            // Remover el prefijo si existe
            return array_map(function ($key) use ($prefix) {
                if ($prefix && str_starts_with($key, (string) $prefix)) {
                    return mb_substr($key, mb_strlen((string) $prefix));
                }

                return $key;
            }, $keys);
        } catch (Exception $e) {
            error('Error al obtener claves de Redis: '.$e->getMessage());

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
            $keys = [];

            foreach ($files as $file) {
                // El nombre del archivo en Laravel es un hash, pero podemos leer el contenido
                $content = File::get($file->getPathname());

                // Formato del archivo de caché de Laravel: expiration_time + serialized_value
                // Intentar extraer el nombre de la clave del contenido serializado
                $keys[] = $file->getFilename();
            }

            return $keys;
        } catch (Exception $e) {
            error('Error al obtener claves del sistema de archivos: '.$e->getMessage());

            return [];
        }
    }

    private function getDatabaseKeys(): array
    {
        try {
            $table = config('cache.stores.database.table', 'cache');

            return DB::table($table)->pluck('key')->toArray();
        } catch (Exception $e) {
            error('Error al obtener claves de la base de datos: '.$e->getMessage());

            return [];
        }
    }

    private function getArrayKeys(): array
    {
        // The array driver doesn't persist between requests, but we can try to get the keys
        // if the store has a method to list them
        warning('The "array" driver does not persist keys between requests.');

        return [];
    }

    private function handleUnsupportedDriver(): array
    {
        error("⚠️  The driver '{$this->driver}' is not currently supported.");
        info('Supported drivers: redis, file, database');

        return [];
    }

    private function getValuePreview(mixed $value): string
    {
        $previewLimit = config('cache-ui-laravel.preview_limit', 100);

        if (is_null($value)) {
            return '<fg=gray>(null)</>';
        }

        if (is_bool($value)) {
            return $value ? '<fg=green>true</>' : '<fg=red>false</>';
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            if (mb_strlen($json) > $previewLimit) {
                return mb_substr($json, 0, $previewLimit).'<fg=gray>...</>';
            }

            return $json;
        }

        $stringValue = (string) $value;
        if (mb_strlen($stringValue) > $previewLimit) {
            return mb_substr($stringValue, 0, $previewLimit).'<fg=gray>...</>';
        }

        return $stringValue;
    }

    private function getFileKeyValue(string $filename): mixed
    {
        try {
            $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));
            $filePath = $cachePath.'/'.$filename;

            if (! File::exists($filePath)) {
                return null;
            }

            $content = File::get($filePath);

            // Laravel file cache format: expiration_time + serialized_value
            if (mb_strlen($content) < 10) {
                return null;
            }

            $expiration = mb_substr($content, 0, 10);
            $serialized = mb_substr($content, 10);

            // Check if expired
            if (time() > $expiration) {
                return null;
            }

            return unserialize($serialized);
        } catch (Exception) {
            return null;
        }
    }

    private function deleteFileKey(string $filename): bool
    {
        try {
            $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));
            $filePath = $cachePath.'/'.$filename;

            if (File::exists($filePath)) {
                return File::delete($filePath);
            }

            return false;
        } catch (Exception) {
            return false;
        }
    }
}
