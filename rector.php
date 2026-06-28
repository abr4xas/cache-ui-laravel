<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
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
    ->withPhpSets();
