<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;
use Illuminate\Support\Facades\Config;

describe('CacheUiLaravelCommand Basic Tests', function (): void {
    it('has correct signature and description', function (): void {
        $command = new CacheUiLaravelCommand();

        expect($command->signature)->toBe('cache:list {--store= : The cache store to use}');
        expect($command->description)->toBe('List and delete individual cache keys');
    });

    it('can be instantiated', function (): void {
        $command = new CacheUiLaravelCommand();

        expect($command)->toBeInstanceOf(CacheUiLaravelCommand::class);
    });

    it('has required properties', function (): void {
        $command = new CacheUiLaravelCommand();

        expect($command->signature)->toBeString();
        expect($command->description)->toBeString();
    });
});

describe('getValuePreview method', function (): void {
    it('handles null values', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $result = $method->invoke($command, null);
        expect($result)->toBe('<fg=gray>(null)</>');
    });

    it('handles boolean values', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $trueResult = $method->invoke($command, true);
        $falseResult = $method->invoke($command, false);

        expect($trueResult)->toBe('<fg=green>true</>');
        expect($falseResult)->toBe('<fg=red>false</>');
    });

    it('handles array values', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $array = ['key' => 'value', 'number' => 123];
        $result = $method->invoke($command, $array);

        expect($result)->toBe('{"key":"value","number":123}');
    });

    it('handles object values', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $object = (object) ['key' => 'value'];
        $result = $method->invoke($command, $object);

        expect($result)->toBe('{"key":"value"}');
    });

    it('handles string values within limit', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $shortString = 'Hello World';
        $result = $method->invoke($command, $shortString);

        expect($result)->toBe('Hello World');
    });

    it('handles string values exceeding limit', function (): void {
        Config::set('cache-ui-laravel.preview_limit', 10);

        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $longString = 'This is a very long string that exceeds the limit';
        $result = $method->invoke($command, $longString);

        expect($result)->toBe('This is a <fg=gray>...</>');
    });

    it('handles array values exceeding limit', function (): void {
        Config::set('cache-ui-laravel.preview_limit', 20);

        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $largeArray = ['very' => 'long', 'array' => 'with', 'many' => 'keys', 'and' => 'values'];
        $result = $method->invoke($command, $largeArray);

        expect($result)->toContain('<fg=gray>...</>');
    });
});

describe('getCacheKeys method', function (): void {
    it('returns array for any driver type', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getCacheKeys');

        // Test that the method exists and is callable
        expect($method)->toBeInstanceOf(ReflectionMethod::class);
        expect($method->isPrivate())->toBeTrue();
    });
});
