<?php

namespace Ombimo\LaravelHtmlCache\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ombimo\LaravelHtmlCache\Rules\Rule;
use Ombimo\LaravelHtmlCache\Rules\RuleSet;

/**
 * Maps a request to a relative file path and back:
 *
 *   example.com/artikel/foo/index.html            GET /artikel/foo
 *   example.com/index.html                        GET /
 *   example.com/artikel/index__q-<hash>.html      GET /artikel?page=2
 *
 * Every path segment is either made of safe characters or replaced by a hash,
 * so a request can never escape the store or collide with the file names.
 */
final class KeyGenerator
{
    public const FILE = 'index.html';

    public const QUERY_PREFIX = 'index__q-';

    private const HASH_PREFIX = '_h-';

    private const MAX_SEGMENT = 100;

    private const MAX_PATH = 180;

    private const MAX_QUERY_VALUE = 128;

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

        return $this->directory($request->getHost(), RuleSet::normalize($request->path()))
            .'/'.$this->fileName($query);
    }

    /**
     * Directory that holds every variant of a page, e.g. "example.com/artikel/foo".
     */
    public function directory(string $host, string $path): string
    {
        $path = RuleSet::normalize($path);
        $directory = $this->host($host);

        if ($path === '/') {
            return $directory;
        }

        $segments = array_map($this->segment(...), explode('/', $path));
        $joined = implode('/', $segments);

        if (strlen($joined) > self::MAX_PATH) {
            $joined = self::HASH_PREFIX.substr(sha1($path), 0, 20);
        }

        return $directory.'/'.$joined;
    }

    public function host(string $host): string
    {
        $host = trim(preg_replace('~[^a-z0-9.\-]~', '_', strtolower($host)) ?? '', '.');

        return $host === '' ? '_' : substr($host, 0, self::MAX_SEGMENT);
    }

    /**
     * @return array{host: string, path: string|null, query: bool}|null path is null when it was hashed
     */
    public function parse(string $key): ?array
    {
        if (preg_match('~^(?<host>[^/]+)/(?:(?<path>.+)/)?index(?<query>__q-[a-f0-9]{20})?\.html$~', $key, $matches) !== 1) {
            return null;
        }

        $path = ($matches['path'] ?? '') === '' ? '/' : $matches['path'];

        if ($path !== '/') {
            foreach (explode('/', $path) as $segment) {
                if (str_starts_with($segment, self::HASH_PREFIX)) {
                    $path = null;

                    break;
                }
            }
        }

        return [
            'host' => $matches['host'],
            'path' => $path,
            'query' => ($matches['query'] ?? '') !== '',
        ];
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

    /**
     * @param  array<string, string>  $query
     */
    private function fileName(array $query): string
    {
        if ($query === []) {
            return self::FILE;
        }

        return self::QUERY_PREFIX.substr(sha1(http_build_query($query)), 0, 20).'.html';
    }

    private function segment(string $segment): string
    {
        $safe = preg_match('#^[A-Za-z0-9._~%-]{1,'.self::MAX_SEGMENT.'}$#', $segment) === 1
            && ! str_starts_with($segment, '.')
            && ! str_ends_with($segment, '.')
            && $segment !== self::FILE
            && ! str_starts_with($segment, self::QUERY_PREFIX)
            && ! str_starts_with($segment, self::HASH_PREFIX)
            && preg_match('~^(con|prn|aux|nul|com\d|lpt\d)(\..*)?$~i', $segment) !== 1;

        return $safe ? $segment : self::HASH_PREFIX.substr(sha1($segment), 0, 20);
    }
}
