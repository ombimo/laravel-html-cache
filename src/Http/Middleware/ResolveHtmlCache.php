<?php

namespace Ombimo\LaravelHtmlCache\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ombimo\LaravelHtmlCache\LaravelHtmlCache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a cached page when there is a fresh one. Register it globally and
 * prepend it so a hit never starts the session, cookies or the router.
 *
 *     $middleware->prepend(ResolveHtmlCache::class);
 */
class ResolveHtmlCache
{
    /** Request attribute holding the key the writer should store the page under. */
    public const KEY_ATTRIBUTE = 'html-cache.key';

    public function __construct(private readonly LaravelHtmlCache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rule = $this->cache->ruleFor($request);

        if ($rule === null) {
            return $next($request);
        }

        $key = $this->cache->isBypassed($request) ? null : $this->cache->keyFor($request, $rule);

        if ($key === null) {
            return $this->cache->tag($next($request), 'BYPASS');
        }

        $page = $this->cache->fresh($rule, $key, $request);

        if ($page !== null) {
            return $this->cache->respond($request, $page);
        }

        $request->attributes->set(self::KEY_ATTRIBUTE, $key);

        return $next($request);
    }
}
