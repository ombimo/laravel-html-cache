<?php

namespace Ombimo\LaravelHtmlCache\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ombimo\LaravelHtmlCache\LaravelHtmlCache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores the rendered page after the application produced it. The response is
 * checked in handle() and the file is written in terminate(), after the
 * response has been sent to the visitor.
 *
 *     $middleware->append(WriteHtmlCache::class);
 */
class WriteHtmlCache
{
    private const STORE_ATTRIBUTE = 'html-cache.store';

    public function __construct(private readonly LaravelHtmlCache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $key = $request->attributes->get(ResolveHtmlCache::KEY_ATTRIBUTE) ?? $this->keyWithoutResolver($request);

        if (! is_string($key) || ! $this->cache->shouldStore($request, $response)) {
            return $response;
        }

        $request->attributes->set(self::STORE_ATTRIBUTE, $key);

        return $this->cache->tag($response, 'MISS');
    }

    public function terminate(Request $request, Response $response): void
    {
        $key = $request->attributes->get(self::STORE_ATTRIBUTE);

        if (is_string($key) && is_string($content = $response->getContent())) {
            $this->cache->put($key, $content);
        }
    }

    /**
     * Fallback when ResolveHtmlCache is not installed: the page is simply
     * regenerated and rewritten on every request.
     */
    private function keyWithoutResolver(Request $request): ?string
    {
        $rule = $this->cache->ruleFor($request);

        if ($rule === null || $this->cache->isBypassed($request)) {
            return null;
        }

        return $this->cache->keyFor($request, $rule);
    }
}
