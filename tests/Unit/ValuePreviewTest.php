<?php

declare(strict_types=1);

use Abr4xas\CacheUiLaravel\Commands\CacheUiLaravelCommand;

describe('Value Preview Method', function (): void {
    it('handles null values correctly', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $preview = $method->invoke($command, null);
        expect($preview)->toBe('<fg=gray>(null)</>');
    });

    it('handles string values correctly', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $testString = 'This is a test string';
        $preview = $method->invoke($command, $testString);
        expect($preview)->toBe($testString);
    });

    it('handles boolean values correctly', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $truePreview = $method->invoke($command, true);
        expect($truePreview)->toBe('<fg=green>true</>');

        $falsePreview = $method->invoke($command, false);
        expect($falsePreview)->toBe('<fg=red>false</>');
    });

    it('handles array values correctly', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        $testArray = ['user_id' => 123, 'name' => 'Angel Cruz'];
        $preview = $method->invoke($command, $testArray);
        expect($preview)->toContain('user_id');
        expect($preview)->toContain('Angel Cruz');
    });

    it('handles long strings with truncation', function (): void {
        $command = new CacheUiLaravelCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getValuePreview');

        // Create a long string (over 100 characters)
        $longString = str_repeat('A', 150);
        $preview = $method->invoke($command, $longString);

        // Debug: let's see what we get
        expect($preview)->toContain('...');
        expect($preview)->toContain('<fg=gray>...</>'); // The actual format used
    });
});
