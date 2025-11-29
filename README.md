<picture>
<img alt="Cache UI Laravel" src="art/cache-ui-laravel.png">
</picture>

# Cache UI Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/abr4xas/cache-ui-laravel.svg?style=flat-square)](https://packagist.org/packages/abr4xas/cache-ui-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/abr4xas/cache-ui-laravel/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/abr4xas/cache-ui-laravel/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/abr4xas/cache-ui-laravel/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/abr4xas/cache-ui-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/abr4xas/cache-ui-laravel.svg?style=flat-square)](https://packagist.org/packages/abr4xas/cache-ui-laravel)

A Laravel package that allows you to list, search and delete individual cache keys without having to purge the entire cache. Supports multiple cache drivers (Redis, File, Database) with an interactive command line interface.

## Installation

You can install the package via composer:

```bash
composer require abr4xas/cache-ui-laravel
```

Optionally, you can publish the config file with:

```bash
php artisan vendor:publish --tag="cache-ui-laravel-config"
```

### Configuration

After publishing the config file, you can customize the package behavior:

```php
return [
    // Default cache store to use
    'default_store' => env('CACHE_UI_DEFAULT_STORE', null),

    // Character limit in value preview
    'preview_limit' => env('CACHE_UI_PREVIEW_LIMIT', 100),

    // Number of visible items in scroll
    'search_scroll' => env('CACHE_UI_SEARCH_SCROLL', 15),
];
```

You can also configure these values in your `.env` file:

```env
CACHE_UI_DEFAULT_STORE=redis
CACHE_UI_PREVIEW_LIMIT=150
CACHE_UI_SEARCH_SCROLL=20
```

### Custom File Cache Driver (Only for File Store)

If you are using the `file` cache driver (default in Laravel), you should use our custom `key-aware-file` driver.

**Why?** The standard Laravel `file` driver stores keys as hashes, making them unreadable. This custom driver wraps the value to store the real key, allowing you to see and search for them.

> **Important**: This is **NOT** needed for Redis or Database drivers, as they support listing keys natively.

#### Driver Configuration

1. **Add the custom store** to your `config/cache.php` file:

```php
// ... existing code ...

    'stores' => [

        // ... existing stores ...

        'file' => [
            'driver' => 'key-aware-file', // Changed from 'file' to 'key-aware-file'
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

// ... existing code ...
```

2. **Register the custom driver** in your `AppServiceProvider`:

```php
<?php

namespace App\Providers;

use Abr4xas\CacheUiLaravel\KeyAwareFileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the custom file cache driver
        Cache::extend('key-aware-file', fn (Application $app, array $config) => Cache::repository(new KeyAwareFileStore(
            $app->make(Filesystem::class),
            $config['path'],
            $config['file_permission'] ?? null
        )));
    }
}
```

#### Custom Driver Benefits

- ✅ **Readable keys**: Shows real keys instead of file hashes
- ✅ **Full compatibility**: Works exactly like the standard `file` driver
- ✅ **Better experience**: Enables more intuitive cache key search and management
- ✅ **Backward compatibility**: Existing cache files continue to work

#### Migration from Standard File Driver

If you already have cached data with the standard `file` driver, don't worry. The `key-aware-file` driver is fully compatible and:

- Existing data will continue to work normally
- New keys will be stored in the new format
- You can migrate gradually without data loss



## Usage

### Basic Command

Run the command to list and manage cache keys:

```bash
php artisan cache:list
```

### Specify a Cache Store

If you have multiple cache stores configured, you can specify which one to use:

```bash
php artisan cache:list --store=redis
```

### Features

- 🔍 **Interactive search**: Search cache keys by typing text
- 📋 **List all keys**: View all available keys in your cache
- 🗑️ **Selective deletion**: Delete individual keys without affecting the rest of the cache
- 🔌 **Multiple drivers**: Supports Redis, File and Database

### Supported Drivers

| Driver | Support | Configuration Required |
|--------|---------|------------------------|
| **Redis** | ✅ Native | None (Works out of the box) |
| **Database** | ✅ Native | None (Works out of the box) |
| **File** | ✅ Enhanced | **Requires `key-aware-file` driver** |
| **Array** | ⚠️ No | Not supported (doesn't persist) |
| **Memcached** | ⚠️ No | Not currently supported |

> **Note**: The `key-aware-file` driver is **only** needed if you use the `file` cache driver. If you use Redis or Database, you don't need to change your driver configuration.

### Usage Example

```bash
$ php artisan cache:list

📦 Cache driver: redis
✅ Found 23 cache keys

🔍 Search and select a cache key to delete
> user_1_profile

📝 Key:     user_1_profile

Are you sure you want to delete this cache key? › No / Yes

🗑️  The key 'user_1_profile' has been successfully deleted
```

### Advanced Command Options

The command supports several useful options:

```bash
# Show cache value before deletion
php artisan cache:list --show-value

# Export keys list to a file
php artisan cache:list --export=keys.txt

# Filter keys by regex pattern
php artisan cache:list --filter="/^user_/"

# Show additional information (size, type, expiration)
php artisan cache:list --info

# Limit number of keys displayed
php artisan cache:list --limit=50

# Combine multiple options
php artisan cache:list --store=redis --show-value --info --limit=100
```

### Programmatic Usage

You can also use the `CacheUiLaravel` class directly in your code:

```php
use Abr4xas\CacheUiLaravel\Facades\CacheUiLaravel;

// Get all cache keys from default store
$keys = CacheUiLaravel::getAllKeys();

// Get all cache keys from a specific store
$redisKeys = CacheUiLaravel::getAllKeys('redis');

// Get limited number of keys (useful for large caches)
$limitedKeys = CacheUiLaravel::getAllKeys('redis', 100);

// Delete a specific key from default store
$deleted = CacheUiLaravel::forgetKey('user_1_profile');

// Delete a key from a specific store
$deleted = CacheUiLaravel::forgetKey('session_data', 'redis');
```

### Advanced Use Cases

#### Batch Operations

```php
use Abr4xas\CacheUiLaravel\Facades\CacheUiLaravel;

// Get all keys matching a pattern
$allKeys = CacheUiLaravel::getAllKeys('redis');
$userKeys = array_filter($allKeys, fn($key) => str_starts_with($key, 'user_'));

// Delete multiple keys
foreach ($userKeys as $key) {
    CacheUiLaravel::forgetKey($key, 'redis');
}
```

#### Monitoring Cache Size

```php
use Abr4xas\CacheUiLaravel\Facades\CacheUiLaravel;
use Illuminate\Support\Facades\Cache;

$keys = CacheUiLaravel::getAllKeys('redis', 1000); // Limit to 1000 for performance
$totalSize = 0;

foreach ($keys as $key) {
    $value = Cache::get($key);
    if ($value !== null) {
        $totalSize += strlen(serialize($value));
    }
}

echo "Total cache size: " . number_format($totalSize / 1024 / 1024, 2) . " MB";
```

#### Cache Key Analysis

```php
use Abr4xas\CacheUiLaravel\Facades\CacheUiLaravel;

$keys = CacheUiLaravel::getAllKeys('redis');
$patterns = [];

foreach ($keys as $key) {
    $prefix = explode('_', $key)[0] ?? 'unknown';
    $patterns[$prefix] = ($patterns[$prefix] ?? 0) + 1;
}

arsort($patterns);
print_r($patterns); // Shows key distribution by prefix
```

## Testing

```bash
composer test:unit
```

## TODO

The following tests and improvements are planned or in progress:

### Unit Tests for KeyAwareFileStore
- [x] Test `put()` method with various data types (string, integer, array, boolean, null)
- [x] Test `get()` method with wrapped and unwrapped data formats
- [x] Test `add()` method behavior and return values
- [x] Test `forever()` method with zero expiration
- [x] Test `increment()` method with numeric values
- [x] Test `decrement()` method with numeric values
- [x] Test backward compatibility with legacy cache files
- [x] Test error handling for corrupted cache files
- [x] Test file permissions and directory creation
- [x] Test `remember()` and `rememberForever()` methods
- [x] Test `pull()`, `has()`, and `flush()` methods

### Integration Tests
- [x] Test complete cache workflow (store → retrieve → delete)
- [x] Test multiple keys with different expiration times
- [x] Test cache key listing with `getAllKeys()` method
- [x] Test cache key deletion with `forgetKey()` method
- [x] Test mixed wrapped and legacy data scenarios
- [x] Test performance with large numbers of cache keys

### Driver Registration Tests
- [ ] Test custom driver registration in `AppServiceProvider`
- [ ] Test driver configuration with different file permissions
- [ ] Test driver fallback behavior with missing configuration
- [ ] Test driver isolation between different cache stores
- [ ] Test error handling for invalid paths and permissions

### CacheUiLaravel Integration Tests
- [x] Test `getAllKeys()` method with `key-aware-file` driver
- [x] Test `forgetKey()` method with `key-aware-file` driver
- [x] Test mixed driver scenarios (Redis + File + Database)
- [x] Test error handling and graceful degradation

### Edge Cases and Error Handling
- [x] Test with invalid serialized data
- [x] Test with very large cache values
- [x] Test with special characters in cache keys
- [ ] Test with read-only file systems
- [ ] Test with insufficient disk space

### Performance Tests
- [ ] Test benchmark for operations with many keys
- [ ] Test load scenarios to validate optimizations

## Configuration Options

The package provides several configuration options in `config/cache-ui-laravel.php`:

- `default_store`: Default cache store to use
- `preview_limit`: Maximum characters to display in value preview (default: 100)
- `search_scroll`: Number of visible items in search menu (default: 15)
- `keys_limit`: Maximum number of keys to retrieve (null = unlimited)
- `enable_logging`: Enable error logging for cache operations (default: false)
- `operation_timeout`: Timeout in seconds for cache operations (0 = no timeout)

You can also configure these via environment variables:

```env
CACHE_UI_DEFAULT_STORE=redis
CACHE_UI_PREVIEW_LIMIT=150
CACHE_UI_SEARCH_SCROLL=20
CACHE_UI_KEYS_LIMIT=1000
CACHE_UI_ENABLE_LOGGING=true
CACHE_UI_OPERATION_TIMEOUT=30
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Angel](https://github.com/abr4xas)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
