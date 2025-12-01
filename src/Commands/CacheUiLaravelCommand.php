<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel\Commands;

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\search;
use function Laravel\Prompts\warning;

final class CacheUiLaravelCommand extends Command
{
    public $signature = 'cache:list
                        {--store= : The cache store to use}
                        {--show-value : Show cache value before deletion}
                        {--export= : Export keys list to file}
                        {--filter= : Filter keys by regex pattern}
                        {--info : Show additional information (size, expiration)}
                        {--limit= : Limit number of keys to display}';

    public $description = 'List and delete individual cache keys';

    private string $driver;

    private string $storeName;

    private CacheUiLaravel $cacheUiLaravel;

    public function handle(CacheUiLaravel $cacheUiLaravel): int
    {
        $this->cacheUiLaravel = $cacheUiLaravel;
        $this->storeName = $this->option('store') ?? config('cache-ui-laravel.default_store') ?? config('cache.default');

        // Validate store name
        if (! isset($this->storeName) || ($this->storeName === '' || $this->storeName === '0')) {
            error('❌ Invalid cache store name');

            return self::FAILURE;
        }

        // Validate that store exists
        $stores = config('cache.stores', []);
        if (! isset($stores[$this->storeName])) {
            error("❌ Cache store '{$this->storeName}' does not exist");

            return self::FAILURE;
        }

        $this->driver = config("cache.stores.{$this->storeName}.driver");

        info("📦 Cache driver: {$this->driver}");

        // Get limit if provided
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $keys = $this->cacheUiLaravel->getAllKeys($this->storeName, $limit);

        // Apply regex filter if provided
        $filter = $this->option('filter');
        if ($filter) {
            $keys = array_filter($keys, fn (string $key): bool => preg_match($filter, $key) === 1);
        }

        // Export keys if requested
        $exportPath = $this->option('export');
        if ($exportPath) {
            return $this->exportKeys($keys, $exportPath);
        }

        if ($keys === []) {
            warning('⚠️  No cache keys found.');

            return self::SUCCESS;
        }

        info('✅ Found '.count($keys).' cache keys');

        $searchScroll = config('cache-ui-laravel.search_scroll', 15);

        $selectedKey = search(
            label: '🔍 Search and select a cache key to delete',
            options: fn (string $value): array => mb_strlen($value) > 0
                ? array_filter($keys, fn ($key): bool => str_contains(mb_strtolower($key), mb_strtolower($value)))
                : $keys,
            placeholder: 'Type to search...',
            scroll: $searchScroll
        );

        if ($selectedKey === 0 || ($selectedKey === '' || $selectedKey === '0')) {
            info('👋 Operation cancelled');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("📝 <fg=cyan>Key:</>      {$selectedKey}");

        // Show value if requested
        if ($this->option('show-value')) {
            $this->displayKeyValue($selectedKey);
        }

        // Show additional info if requested
        if ($this->option('info')) {
            $this->displayKeyInfo($selectedKey);
        }

        $this->newLine();

        $confirmed = confirm(
            label: 'Are you sure you want to delete this cache key?',
            default: false
        );

        if (! $confirmed) {
            info('👋 Operation cancelled');

            return self::SUCCESS;
        }

        // Use CacheUiLaravel to delete the key
        $deleted = $this->cacheUiLaravel->forgetKey($selectedKey, $this->storeName);

        if ($deleted) {
            info("🗑️  The key '{$selectedKey}' has been successfully deleted");

            return self::SUCCESS;
        }

        error("❌ Could not delete the key '{$selectedKey}'");

        return self::FAILURE;
    }

    /**
     * Display the value of a cache key
     */
    private function displayKeyValue(string $key): void
    {
        try {
            $value = Cache::store($this->storeName)->get($key);
            $previewLimit = config('cache-ui-laravel.preview_limit', 100);

            $this->newLine();
            $this->line('<fg=yellow>Value:</>');

            if ($value === null) {
                $this->line('  <fg=gray>(null)</>');
            } elseif (is_string($value)) {
                $preview = mb_strlen($value) > $previewLimit
                    ? mb_substr($value, 0, $previewLimit).'...'
                    : $value;
                $this->line('  '.$preview);
                if (mb_strlen($value) > $previewLimit) {
                    $this->line('  <fg=gray>(truncated, full length: '.mb_strlen($value).' characters)</>');
                }
            } elseif (is_array($value)) {
                $this->line('  <fg=gray>(array with '.count($value).' items)</>');
                $this->line('  '.json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } elseif (is_object($value)) {
                $this->line('  <fg=gray>(object: '.$value::class.')</>');
                $this->line('  '.json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('  '.var_export($value, true));
            }
        } catch (Exception $e) {
            $this->line('  <fg=red>Error retrieving value: '.$e->getMessage().'</>');
        }
    }

    /**
     * Display additional information about a cache key
     */
    private function displayKeyInfo(string $key): void
    {
        try {
            $cacheStore = Cache::store($this->storeName);
            $value = $cacheStore->get($key);

            $this->newLine();
            $this->line('<fg=yellow>Additional Information:</>');

            // Size information
            if ($value !== null) {
                $size = mb_strlen(serialize($value));
                $this->line('  <fg=cyan>Size:</>      '.$this->formatBytes($size));
            }

            // Type information
            $type = gettype($value);
            $this->line("  <fg=cyan>Type:</>      {$type}");

            // For arrays and objects, show count
            if (is_array($value)) {
                $this->line('  <fg=cyan>Items:</>     '.count($value));
            } elseif (is_object($value)) {
                $this->line('  <fg=cyan>Class:</>     '.$value::class);
            }
        } catch (Exception $e) {
            $this->line('  <fg=red>Error retrieving info: '.$e->getMessage().'</>');
        }
    }

    /**
     * Export keys to a file
     */
    private function exportKeys(array $keys, string $path): int
    {
        try {
            $content = implode("\n", $keys);
            File::put($path, $content);
            info('✅ Exported '.count($keys)." keys to: {$path}");

            return self::SUCCESS;
        } catch (Exception $e) {
            error('❌ Failed to export keys: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
