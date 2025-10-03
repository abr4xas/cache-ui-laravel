<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;

describe('CacheUiLaravelCommand Method Tests', function (): void {
    describe('getArrayKeys method', function (): void {
        it('returns empty array and shows warning', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('getArrayKeys');

            $result = $method->invoke($command);
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });
    });

    describe('handleUnsupportedDriver method', function (): void {
        it('exists and is private', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('handleUnsupportedDriver');

            expect($method->isPrivate())->toBeTrue();
            expect($method->getReturnType()->getName())->toBe('array');
        });
    });

    describe('getFileKeyValue method', function (): void {
        it('returns null when file does not exist', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('getFileKeyValue');

            $result = $method->invoke($command, 'nonexistent_file');
            expect($result)->toBeNull();
        });
    });

    describe('deleteFileKey method', function (): void {
        it('returns false when file does not exist', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('deleteFileKey');

            $result = $method->invoke($command, 'nonexistent_file');
            expect($result)->toBeFalse();
        });
    });

    describe('method existence', function (): void {
        it('has all required private methods', function (): void {
            $command = new CacheUiLaravelCommand();
            $reflection = new ReflectionClass($command);

            $expectedMethods = [
                'getCacheKeys',
                'getRedisKeys',
                'getFileKeys',
                'getDatabaseKeys',
                'getArrayKeys',
                'handleUnsupportedDriver',
                'getFileKeyValue',
                'deleteFileKeyByKey',
                'deleteFileKey',
            ];

            foreach ($expectedMethods as $methodName) {
                expect($reflection->hasMethod($methodName))->toBeTrue();

                $method = $reflection->getMethod($methodName);
                expect($method->isPrivate())->toBeTrue();
            }
        });
    });
});
