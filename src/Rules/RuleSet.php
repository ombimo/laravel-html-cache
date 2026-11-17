<?php

namespace Ombimo\LaravelHtmlCache\Rules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * Compiled view of the "routes" and "except" config. Matching works on the
 * request path because the resolver runs before the router.
 */
final class RuleSet
{
    /** @var list<Rule> */
    private array $rules = [];

    /**
     * @param  array<int|string, mixed>  $routes
     * @param  list<string>  $except
     */
    public function __construct(array $routes, private readonly array $except, ?int $defaultTtl)
    {
        foreach ($routes as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $this->rules[] = new Rule(self::pattern($value), $defaultTtl);
                }

                continue;
            }

            $this->rules[] = $this->compile(self::pattern($key), $value, $defaultTtl);
        }
    }

    public static function fromConfig(Repository $config): self
    {
        $defaultTtl = $config->get('html-cache.default_ttl');

        return new self(
            (array) $config->get('html-cache.routes', []),
            array_map(self::pattern(...), array_values(array_filter((array) $config->get('html-cache.except', []), is_string(...)))),
            is_numeric($defaultTtl) ? (int) $defaultTtl : null,
        );
    }

    /**
     * "/" for the home page, otherwise the path without leading or trailing slash.
     */
    public static function normalize(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Wildcard patterns are normalized like paths. A pattern starting with "~"
     * is a regular expression and is kept as written.
     */
    private static function pattern(string $pattern): string
    {
        return str_starts_with($pattern, '~') ? $pattern : self::normalize($pattern);
    }

    /**
     * "~^venue/\d+/[^/]+$~" is a regex, anything else a Str::is wildcard
     * (where "*" also matches slashes).
     */
    private static function matches(string $pattern, string $path): bool
    {
        if (str_starts_with($pattern, '~')) {
            return preg_match($pattern, $path) === 1;
        }

        return Str::is($pattern, $path);
    }

    /**
     * @param  string  $path  a normalized path
     */
    public function match(string $path): ?Rule
    {
        foreach ($this->except as $pattern) {
            if (self::matches($pattern, $path)) {
                return null;
            }
        }

        foreach ($this->rules as $rule) {
            if (self::matches($rule->pattern, $path)) {
                return $rule;
            }
        }

        return null;
    }

    private function compile(string $pattern, mixed $value, ?int $defaultTtl): Rule
    {
        if (is_array($value)) {
            $ttl = array_key_exists('ttl', $value) ? self::ttl($value['ttl'], $defaultTtl) : $defaultTtl;
            $query = is_array($value['query'] ?? null) ? $value['query'] : [];

            return new Rule($pattern, $ttl, $query);
        }

        return new Rule($pattern, self::ttl($value, $defaultTtl));
    }

    private static function ttl(mixed $value, ?int $defaultTtl): ?int
    {
        return match (true) {
            $value === true => $defaultTtl,
            is_numeric($value) => max(0, (int) $value),
            default => null,
        };
    }
}
