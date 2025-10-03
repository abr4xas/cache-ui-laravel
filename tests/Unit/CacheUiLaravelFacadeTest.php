<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Facades\CacheUiLaravel;
use Illuminate\Support\Facades\Facade;

describe('CacheUiLaravel Facade Tests', function (): void {
    describe('Facade structure', function (): void {
        it('extends Laravel Facade', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravel::class);
            expect($reflection->getParentClass()->getName())->toBe(Facade::class);
        });

        it('is final class', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravel::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        it('has correct facade accessor', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravel::class);
            $method = $reflection->getMethod('getFacadeAccessor');

            $accessor = $method->invoke(null);
            expect($accessor)->toBe(Abr4xas\CacheUiLaravel\CacheUiLaravel::class);
        });
    });

    describe('Facade functionality', function (): void {
        it('has correct facade accessor method', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravel::class);
            $method = $reflection->getMethod('getFacadeAccessor');

            expect($method->isProtected())->toBeTrue();
            expect($method->isStatic())->toBeTrue();
        });
    });

    describe('Documentation', function (): void {
        it('has proper docblock', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravel::class);
            $docComment = $reflection->getDocComment();

            expect($docComment)->toContain('@see');
            expect($docComment)->toContain('CacheUiLaravel');
        });
    });
});
