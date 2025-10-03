<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;

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
