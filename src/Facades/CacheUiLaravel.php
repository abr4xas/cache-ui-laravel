<?php

declare(strict_types=1);

namespace Abr4xas\CacheUiLaravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Abr4xas\CacheUiLaravel\CacheUiLaravel
 */
final class CacheUiLaravel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Abr4xas\CacheUiLaravel\CacheUiLaravel::class;
    }
}
