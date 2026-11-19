<?php

namespace Ombimo\LaravelHtmlCache\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ombimo\LaravelHtmlCache\Contracts\PathGenerator;
use Ombimo\LaravelHtmlCache\Paths\DefaultPathGenerator;
use Ombimo\LaravelHtmlCache\Rules\Rule;
use Ombimo\LaravelHtmlCache\Rules\RuleSet;

/**
 * Turns a request into a cache key. It decides which query parameters count
 * (config "query" and the route's own allowlist) and leaves the file layout to
 * the configured PathGenerator, e.g.
 *
 *   default                   example.com/artikel/foo/index.html
 *   last_as_name              example.com/artikel/foo.html
 *   last_as_name_with_prefix  example.com/ab/cd/artikel/foo.html
 */
final class KeyGenerator
{
    private const MAX_QUERY_VALUE = 128;

    public function __construct(
        private readonly PathGenerator $paths = new DefaultPathGenerator,
    ) {}

    /**
     * @param  list<string>  $ignore  query parameter patterns dropped from the key
     * @param  bool  $bypassUnknown  true: unknown parameters make the request uncacheable
     * @return string|null null when the request must not use the cache
     */
    public function forRequest(Request $request, Rule $rule, array $ignore = [], bool $bypassUnknown = true): ?string
    {
        $query = $this->significantQuery($request->query->all(), $rule->query, $ignore, $bypassUnknown);

        if ($query === null) {
            return null;
        }

        return $this->paths->key($request->getHost(), RuleSet::normalize($request->path()), $query);
    }

    /**
     * Directory that holds the file of a page and its query variants.
     */
    public function directory(string $host, string $path): string
    {
        return $this->paths->directory($host, RuleSet::normalize($path));
    }

    /**
     * Whether a key is the page itself or one of its query variants.
     */
    public function belongsTo(string $key, string $host, string $path): bool
    {
        return $this->paths->belongsTo($key, $host, RuleSet::normalize($path));
    }

    public function host(string $host): string
    {
        return $this->paths->host($host);
    }

    /**
     * @return array{host: string, path: string|null, query: bool}|null path is null when it was hashed
     */
    public function parse(string $key): ?array
    {
        return $this->paths->parse($key);
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @param  array<int|string, string>  $allowed  list of names, or name => "~regex~"
     * @param  list<string>  $ignore
     * @return array<string, string>|null null when a parameter makes the request uncacheable
     */
    private function significantQuery(array $params, array $allowed, array $ignore, bool $bypassUnknown): ?array
    {
        $kept = [];

        foreach ($params as $name => $value) {
            $name = (string) $name;

            if ($ignore !== [] && Str::is($ignore, $name)) {
                continue;
            }

            $constraint = $this->constraint($allowed, $name);

            if ($constraint === false) {
                if ($bypassUnknown) {
                    return null;
                }

                continue;
            }

            if (! is_string($value) || strlen($value) > self::MAX_QUERY_VALUE) {
                return null;
            }

            if ($constraint !== null && preg_match($constraint, $value) !== 1) {
                return null;
            }

            $kept[$name] = $value;
        }

        ksort($kept);

        return $kept;
    }

    /**
     * @param  array<int|string, string>  $allowed
     * @return string|false|null a regex, null when any value is fine, false when the name is not allowed
     */
    private function constraint(array $allowed, string $name): string|false|null
    {
        foreach ($allowed as $key => $value) {
            if (is_int($key) && $value === $name) {
                return null;
            }

            if (! is_int($key) && $key === $name) {
                return $value;
            }
        }

        return false;
    }
}
