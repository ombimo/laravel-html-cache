<?php

namespace Ombimo\LaravelHtmlCache;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ombimo\LaravelHtmlCache\Rules\Rule;
use Ombimo\LaravelHtmlCache\Rules\RuleSet;
use Ombimo\LaravelHtmlCache\Store\CachedPage;
use Ombimo\LaravelHtmlCache\Store\Store;
use Ombimo\LaravelHtmlCache\Support\KeyGenerator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Brains of the package, shared by both middleware, the artisan command and
 * the facade. Config is read on every call so changes apply immediately.
 */
class LaravelHtmlCache
{
    public function __construct(
        private readonly Repository $config,
        private readonly Store $store,
        private readonly KeyGenerator $keys,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('html-cache.enabled', true);
    }

    /**
     * The rule that applies to this request, null when it is not cacheable at all.
     */
    public function ruleFor(Request $request): ?Rule
    {
        if (! $this->enabled() || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        return $this->rules()->match(RuleSet::normalize($request->path()));
    }

    public function isBypassed(Request $request): bool
    {
        $header = $this->config->get('html-cache.bypass.header');

        if (is_string($header) && $header !== '' && $request->headers->has($header)) {
            return true;
        }

        foreach ((array) $this->config->get('html-cache.bypass.cookies', []) as $cookie) {
            if (is_string($cookie) && $request->cookies->has($cookie)) {
                return true;
            }
        }

        $hosts = $this->config->get('html-cache.hosts');

        return is_array($hosts) && $hosts !== [] && ! Str::is($hosts, strtolower($request->getHost()));
    }

    /**
     * Cache key of the request, null when its query string makes it uncacheable.
     */
    public function keyFor(Request $request, Rule $rule): ?string
    {
        return $this->keys->forRequest(
            $request,
            $rule,
            array_values(array_filter((array) $this->config->get('html-cache.query.ignore', []), is_string(...))),
            $this->config->get('html-cache.query.unknown', 'bypass') !== 'ignore',
        );
    }

    /**
     * The stored page when it exists and has not expired.
     */
    public function fresh(Rule $rule, string $key, ?Request $request = null): ?CachedPage
    {
        $modified = $this->store->lastModified($key);

        if ($modified === null) {
            return null;
        }

        if ($rule->ttl !== null && Carbon::now()->getTimestamp() - $modified > $rule->ttl) {
            return null;
        }

        // A page stored as gzip goes to a client that accepts it as it is, no decompression.
        if ($request !== null && $this->servesEncoded() && $this->acceptsGzip($request)) {
            $gzip = $this->store->getGzip($key);

            if ($gzip !== null) {
                return new CachedPage($gzip, $modified, gzipped: true);
            }
        }

        $html = $this->store->get($key);

        return $html === null ? null : new CachedPage($html, $modified);
    }

    /**
     * gzip level new pages are stored with, null when compression is off.
     *
     * @param  mixed  $compress  the "compress" config value
     */
    public static function level(mixed $compress): ?int
    {
        $compress = is_array($compress) ? $compress : [];

        if (! ($compress['enabled'] ?? false)) {
            return null;
        }

        return max(1, min(9, is_numeric($compress['level'] ?? null) ? (int) $compress['level'] : 6));
    }

    private function servesEncoded(): bool
    {
        return (bool) $this->config->get('html-cache.compress.serve_encoded', true);
    }

    private function acceptsGzip(Request $request): bool
    {
        $header = strtolower((string) $request->headers->get('Accept-Encoding'));

        foreach (explode(',', $header) as $coding) {
            [$name, $parameter] = array_pad(explode(';', $coding, 2), 2, '');

            if (in_array(trim($name), ['gzip', 'x-gzip'], true)) {
                return ! preg_match('~q\s*=\s*0(\.0*)?\s*$~', trim($parameter));
            }
        }

        return false;
    }

    public function shouldStore(Request $request, SymfonyResponse $response): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        if (! in_array($response->getStatusCode(), (array) $this->config->get('html-cache.statuses', [200]), true)) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->isRedirection()) {
            return false;
        }

        if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return false;
        }

        if ($response->headers->getCookies() !== [] && $this->config->get('html-cache.cookies', 'skip') !== 'strip') {
            return false;
        }

        $content = $response->getContent();

        return is_string($content)
            && $content !== ''
            && strlen($content) <= (int) $this->config->get('html-cache.max_size', 2 * 1024 * 1024);
    }

    /**
     * Never throws: a cache that cannot write must not break the page.
     */
    public function put(string $key, string $html): bool
    {
        try {
            return $this->store->put($key, $html);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Response for a cached page. Answers 304 when the client already has it.
     */
    public function respond(Request $request, CachedPage $page): Response
    {
        $response = new Response($page->body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        if ($page->gzipped) {
            $response->headers->set('Content-Encoding', 'gzip');
            $response->setVary('Accept-Encoding', false);
        }

        $response->setEtag($page->etag());
        $response->setLastModified(Carbon::createFromTimestampUTC($page->lastModified));

        $cacheControl = $this->config->get('html-cache.headers.cache_control');

        if (is_string($cacheControl) && $cacheControl !== '') {
            $response->headers->set('Cache-Control', $cacheControl);
        }

        $this->tag($response, 'HIT');
        $response->isNotModified($request);

        // Resolved before the router, so nobody else prepares this response: sets
        // Content-Length and drops the body for HEAD and 304.
        return $response->prepare($request);
    }

    /**
     * Adds the debug header (HIT / MISS / BYPASS) when it is enabled.
     */
    public function tag(SymfonyResponse $response, string $state): SymfonyResponse
    {
        $header = $this->config->get('html-cache.headers.debug');

        if (is_string($header) && $header !== '') {
            $response->headers->set($header, $state);
        }

        return $response;
    }

    /**
     * Delete every variant (query strings included) of one page.
     *
     * @param  string  $path  "/artikel/foo" or a full URL
     * @param  string|null  $host  limit to one host, default: every host (or the host in the URL)
     * @return int files deleted
     */
    public function forget(string $path, ?string $host = null): int
    {
        if (str_contains($path, '://')) {
            $host ??= parse_url($path, PHP_URL_HOST) ?: null;
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $path = RuleSet::normalize(explode('?', $path)[0]);
        $hosts = $host !== null ? [$this->keys->host($host)] : $this->store->hosts();
        $deleted = 0;

        foreach ($hosts as $name) {
            foreach ($this->store->files($this->keys->directory($name, $path), recursive: false) as $key) {
                if ($this->keys->belongsTo($key, $name, $path)) {
                    $this->store->delete($key);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete every cached page whose path matches a pattern such as "artikel/*".
     *
     * @return int files deleted
     */
    public function forgetMatching(string $pattern, ?string $host = null): int
    {
        $pattern = RuleSet::normalize($pattern);
        $host = $host !== null ? $this->keys->host($host) : null;
        $deleted = 0;

        foreach ($this->store->files() as $key) {
            $parsed = $this->keys->parse($key);

            if ($parsed === null || $parsed['path'] === null || ($host !== null && $parsed['host'] !== $host)) {
                continue;
            }

            if (Str::is($pattern, $parsed['path'])) {
                $this->store->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete expired pages, and pages whose route is no longer cached.
     *
     * @return int files deleted
     */
    public function prune(): int
    {
        $rules = $this->rules();
        $defaultTtl = $this->config->get('html-cache.default_ttl');
        $defaultTtl = is_numeric($defaultTtl) ? (int) $defaultTtl : null;
        $now = Carbon::now()->getTimestamp();
        $deleted = 0;

        foreach ($this->store->files() as $key) {
            $parsed = $this->keys->parse($key);
            $modified = $this->store->lastModified($key);

            if ($parsed === null || $modified === null) {
                continue;
            }

            // A hashed path cannot be matched back to a rule, so it follows the default TTL.
            $ttl = $parsed['path'] === null ? $defaultTtl : $rules->match($parsed['path'])?->ttl;
            $orphan = $parsed['path'] !== null && $rules->match($parsed['path']) === null;

            if ($orphan || ($ttl !== null && $now - $modified > $ttl)) {
                $this->store->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return int files deleted
     */
    public function flush(): int
    {
        $deleted = 0;

        foreach ($this->store->files() as $key) {
            // Only files the path generator wrote: never a foreign .html in the store directory.
            if ($this->keys->parse($key) !== null) {
                $this->store->delete($key);
                $deleted++;
            }
        }

        $this->store->deleteEmptyDirectories();

        return $deleted;
    }

    private function rules(): RuleSet
    {
        return RuleSet::fromConfig($this->config);
    }
}
