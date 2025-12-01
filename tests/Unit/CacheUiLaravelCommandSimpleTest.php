<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;

describe('CacheUiLaravelCommand Basic Tests', function (): void {
    it('has correct signature and description', function (): void {
        $command = new CacheUiLaravelCommand();

        expect($command->signature)->toContain('cache:list');
        expect($command->signature)->toContain('--store=');
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

describe('command structure', function (): void {
    it('uses CacheUiLaravel for key operations', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);

        // The command should have a cacheUiLaravel property
        expect($reflection->hasProperty('cacheUiLaravel'))->toBeTrue();
    });
});
