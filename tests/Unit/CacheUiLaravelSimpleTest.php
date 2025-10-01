<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;

describe('CacheUiLaravel Class', function (): void {
    it('can be instantiated', function (): void {
        $instance = new CacheUiLaravel();

        expect($instance)->toBeInstanceOf(CacheUiLaravel::class);
    });

    it('is final class', function (): void {
        $reflection = new ReflectionClass(CacheUiLaravel::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('has public methods', function (): void {
        $reflection = new ReflectionClass(CacheUiLaravel::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        // Should have public methods
        expect($methods)->toHaveCount(2);

        $methodNames = array_map(fn (ReflectionMethod $method): string => $method->getName(), $methods);
        expect($methodNames)->toContain('getAllKeys');
        expect($methodNames)->toContain('forgetKey');
    });
});
