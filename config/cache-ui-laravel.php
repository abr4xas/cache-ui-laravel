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

];
