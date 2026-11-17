<?php

namespace Ombimo\LaravelHtmlCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static int forget(string $path, ?string $host = null)
 * @method static int forgetMatching(string $pattern, ?string $host = null)
 * @method static int prune()
 * @method static int flush()
 *
 * @see \Ombimo\LaravelHtmlCache\LaravelHtmlCache
 */
class LaravelHtmlCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ombimo\LaravelHtmlCache\LaravelHtmlCache::class;
    }
}
