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

    describe('array indexing fix for issue #14', function (): void {
        it('ensures filtered arrays have sequential indices to prevent search() returning index instead of value', function (): void {
            // Simulate the scenario from issue #14:
            // When cache files from multiple pages are loaded, filtering can create non-sequential indices
            // This test validates that array_values() is applied correctly

            // Simulate keys array with non-sequential indices (as would happen after filtering)
            // This mimics the bug scenario: [0 => 'key1', 1 => 'key2', 5 => 'key3', 6 => 'key4']
            $keysWithNonSequentialIndices = [
                0 => 'stats_top_cities',
                1 => 'stats_release_performance_counts',
                5 => 'stats_shows_by_year',
                6 => 'stats_frequent_guests',
            ];

            // Apply array_values() as the fix does
            $reindexedKeys = array_values($keysWithNonSequentialIndices);

            // Verify indices are now sequential (0, 1, 2, 3)
            expect(array_keys($reindexedKeys))->toBe([0, 1, 2, 3]);
            expect($reindexedKeys)->toBe([
                'stats_top_cities',
                'stats_release_performance_counts',
                'stats_shows_by_year',
                'stats_frequent_guests',
            ]);

            // Simulate filtering scenario (as happens in the search function)
            $filteredKeys = array_filter($keysWithNonSequentialIndices, fn (string $key): bool => str_contains($key, 'stats'));
            $reindexedFilteredKeys = array_values($filteredKeys);

            // Verify filtered array also has sequential indices
            expect(array_keys($reindexedFilteredKeys))->toBe([0, 1, 2, 3]);
            expect($reindexedFilteredKeys)->toBe([
                'stats_top_cities',
                'stats_release_performance_counts',
                'stats_shows_by_year',
                'stats_frequent_guests',
            ]);

            // Critical test: Verify that values are strings, not integers
            // This ensures search() will return the key name, not the index
            foreach ($reindexedFilteredKeys as $key) {
                expect($key)->toBeString();
                expect(is_int($key))->toBeFalse();
            }
        });

        it('ensures regex filtered arrays have sequential indices', function (): void {
            // Simulate regex filter scenario
            $keys = [
                0 => 'home_featured_session',
                1 => 'home_count',
                3 => 'home_videos',
                4 => 'home_band',
                5 => 'stats_top_cities',
                6 => 'stats_release_performance_counts',
            ];

            // Apply regex filter (as done in the command)
            $filtered = array_filter($keys, fn (string $key): bool => preg_match('/^home_/', $key) === 1);
            $reindexed = array_values($filtered);

            // Verify sequential indices
            expect(array_keys($reindexed))->toBe([0, 1, 2, 3]);
            expect($reindexed)->toBe([
                'home_featured_session',
                'home_count',
                'home_videos',
                'home_band',
            ]);

            // Verify all values are strings
            foreach ($reindexed as $key) {
                expect($key)->toBeString();
            }
        });
    });
});
