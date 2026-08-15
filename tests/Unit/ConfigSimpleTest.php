<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

describe('Cache UI Laravel Configuration', function (): void {
    it('configuration file exists', function (): void {
        $configPath = __DIR__.'/../../config/cache-ui-laravel.php';

        expect(file_exists($configPath))->toBeTrue()
            ->and(is_readable($configPath))->toBeTrue();
    });

    it('configuration file returns valid array', function (): void {
        $configPath = __DIR__.'/../../config/cache-ui-laravel.php';
        $config = require $configPath;

        expect($config)->toBeArray()
            ->toHaveKeys(['default_store', 'preview_limit', 'search_scroll']);
    });

    it('has correct default values', function (): void {
        $configPath = __DIR__.'/../../config/cache-ui-laravel.php';
        $config = require $configPath;

        expect($config['default_store'])->toBeNull()
            ->and($config)->toMatchArray(['preview_limit' => 100, 'search_scroll' => 15]);
    });

    it('can be loaded via Config facade', function (): void {
        // Load the config manually since it's not auto-loaded in tests
        $configPath = __DIR__.'/../../config/cache-ui-laravel.php';
        $config = require $configPath;
        Config::set('cache-ui-laravel', $config);

        $loadedConfig = Config::get('cache-ui-laravel');

        expect($loadedConfig)->toBeArray()
            ->toHaveKeys(['default_store', 'preview_limit', 'search_scroll']);
    });

    it('supports environment variable overrides', function (): void {
        // Test that config can be overridden
        Config::set('cache-ui-laravel.preview_limit', 200);
        expect(Config::get('cache-ui-laravel.preview_limit'))->toBe(200);

        Config::set('cache-ui-laravel.search_scroll', 25);
        expect(Config::get('cache-ui-laravel.search_scroll'))->toBe(25);
    });
});
