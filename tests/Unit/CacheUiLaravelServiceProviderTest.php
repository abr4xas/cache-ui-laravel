<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\CacheUiLaravel;
use Abr4xas\CacheUiLaravel\CacheUiLaravelServiceProvider;

describe('CacheUiLaravelServiceProvider Tests', function (): void {
    describe('ServiceProvider structure', function (): void {
        it('can be instantiated', function (): void {
            $app = Mockery::mock(Illuminate\Contracts\Foundation\Application::class);
            $serviceProvider = new CacheUiLaravelServiceProvider($app);

            expect($serviceProvider)->toBeInstanceOf(CacheUiLaravelServiceProvider::class);
        });

        it('extends Laravel ServiceProvider', function (): void {
            $app = Mockery::mock(Illuminate\Contracts\Foundation\Application::class);
            $serviceProvider = new CacheUiLaravelServiceProvider($app);
            $reflection = new ReflectionClass($serviceProvider);

            expect($reflection->getParentClass()->getName())->toBe(Illuminate\Support\ServiceProvider::class);
        });

        it('is final class', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravelServiceProvider::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        it('has required methods', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravelServiceProvider::class);

            expect($reflection->hasMethod('boot'))->toBeTrue();
            expect($reflection->hasMethod('register'))->toBeTrue();

            $bootMethod = $reflection->getMethod('boot');
            $registerMethod = $reflection->getMethod('register');

            expect($bootMethod->isPublic())->toBeTrue();
            expect($registerMethod->isPublic())->toBeTrue();
        });
    });

    describe('register method', function (): void {
        it('registers CacheUiLaravel binding', function (): void {
            $app = Mockery::mock(Illuminate\Contracts\Foundation\Application::class);
            $app->shouldReceive('singleton')->once()->with(
                CacheUiLaravel::class,
                Mockery::type('Closure')
            );

            $serviceProvider = new CacheUiLaravelServiceProvider($app);
            $serviceProvider->register();
        });
    });

    describe('boot method', function (): void {
        it('has correct method signature', function (): void {
            $reflection = new ReflectionClass(CacheUiLaravelServiceProvider::class);
            $method = $reflection->getMethod('boot');

            expect($method->isPublic())->toBeTrue();
            expect($method->getReturnType()->getName())->toBe('void');
        });
    });
});
