<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // False positive on the Redis SCAN fallback in getRedisKeys(): Laravel's
        // Redis Connection class is annotated `@mixin \Redis`, so Rector resolves
        // scan() to the raw PhpRedis signature and wrongly concludes the `$keys`
        // array is always empty, trying to unwrap the KEYS fallback. See the
        // matching note in phpstan.neon.dist.
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__.'/src/CacheUiLaravel.php',
        ],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpSets()
    // Pest's own Rector rules: modernize expectations and keep test style consistent.
    ->withSets([
        PestSetList::CODING_STYLE,
    ]);
