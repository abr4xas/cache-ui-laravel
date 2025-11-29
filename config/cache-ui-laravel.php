<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | Define which cache store to use by default when running the command.
    | If null, it will use Laravel's default cache store.
    |
    */

    'default_store' => env('CACHE_UI_DEFAULT_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Supported Drivers
    |--------------------------------------------------------------------------
    |
    | List of cache drivers supported by the package.
    | You don't need to modify this unless you extend the package.
    |
    */

    'supported_drivers' => [
        'redis',
        'file',
        'database',
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of characters to display in the value preview
    | of a cache key before deleting it.
    |
    */

    'preview_limit' => env('CACHE_UI_PREVIEW_LIMIT', 100),

    /*
    |--------------------------------------------------------------------------
    | Search Scroll
    |--------------------------------------------------------------------------
    |
    | Number of visible items in the key search menu.
    |
    */

    'search_scroll' => env('CACHE_UI_SEARCH_SCROLL', 15),

    /*
    |--------------------------------------------------------------------------
    | Keys Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of keys to retrieve when listing cache keys.
    | Set to null for unlimited (not recommended for large caches).
    |
    */

    'keys_limit' => env('CACHE_UI_KEYS_LIMIT', null),

    /*
    |--------------------------------------------------------------------------
    | Enable Logging
    |--------------------------------------------------------------------------
    |
    | Enable error logging for cache operations.
    |
    */

    'enable_logging' => env('CACHE_UI_ENABLE_LOGGING', false),

    /*
    |--------------------------------------------------------------------------
    | Operation Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for cache operations (0 = no timeout).
    |
    */

    'operation_timeout' => env('CACHE_UI_OPERATION_TIMEOUT', 0),

];
