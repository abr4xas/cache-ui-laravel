<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;

describe('CacheUiLaravelCommand Method Tests', function (): void {
    describe('command structure', function (): void {
        it('has required properties', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);

            expect($reflection->hasProperty('driver'))->toBeTrue();
            expect($reflection->hasProperty('storeName'))->toBeTrue();
            expect($reflection->hasProperty('cacheUiLaravel'))->toBeTrue();
        });

        it('has helper methods for display', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);

            $expectedMethods = [
                'displayKeyValue',
                'displayKeyInfo',
                'exportKeys',
                'formatBytes',
            ];

            foreach ($expectedMethods as $methodName) {
                expect($reflection->hasMethod($methodName))->toBeTrue();
            }
        });
    });
});
