<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;

describe('CacheUiLaravelCommand Basic Tests', function (): void {
    it('has correct signature and description', function (): void {
        $command = new CacheUiLaravelCommand();

        expect($command->signature)->toContain('cache:list')
            ->toContain('--store=')
            ->and($command->description)->toBe('List and delete individual cache keys');
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
